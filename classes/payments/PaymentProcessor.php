<?php

/** Coordinates customer-owned payment attempts while leaving provider protocol details to a gateway. */
class PaymentProcessor
{
    private const PROVIDER = 'selcom';

    public function __construct(private Database $db, private PaymentGatewayInterface $gateway)
    {
    }

    /** Starts a mobile-money attempt. A retry must reuse its original idempotency key. */
    public function initiatePayment(int $user_id, array $input, string $idempotency_key): array
    {
        PaymentFlow::assertSelcomEnabled();

        $data = $this->validateRequest($input);
        $key = $this->normalizeIdempotencyKey($idempotency_key);
        $existing = $this->findExistingAttempt($user_id, $data['order_id'], $key);
        if ($existing !== null) {
            return $this->getPaymentStatus($user_id, $existing);
        }

        $payment_id = $this->createAttempt($user_id, $data, $key);
        $payment = $this->getAttemptForProvider($payment_id);
        $normalized = $this->sendInitiationRequest($payment, $key);
        $this->saveInitiationResult($payment, $normalized);

        return $this->getPaymentStatus($user_id, $payment_id);
    }

    /** Returns safe status fields only; provider credentials and raw responses never leave the backend. */
    public function getPaymentStatus(int $user_id, int $payment_id): array
    {
        $payment = $this->db->fetchOne(
            'SELECT p.payment_id, p.order_id, p.payment_method_code, p.payment_amount, p.payment_status,
                    p.created_at, o.order_payment_status
             FROM payments p JOIN orders o ON o.order_id = p.order_id
             WHERE p.payment_id = :payment_id AND p.user_id = :user_id AND p.payment_provider = :provider',
            ['payment_id' => $payment_id, 'user_id' => $user_id, 'provider' => self::PROVIDER]
        );
        if ($payment === null) {
            throw ApiException::notFound('Malipo hayakupatikana.');
        }
        return [
            'payment_id' => (int) $payment['payment_id'],
            'order_id' => (int) $payment['order_id'],
            'payment_method_code' => $payment['payment_method_code'],
            'payment_amount' => (int) $payment['payment_amount'],
            'payment_status' => $payment['payment_status'],
            'order_payment_status' => $payment['order_payment_status'],
            'created_at' => isoDate($payment['created_at']),
        ];
    }

    private function validateRequest(array $input): array
    {
        return Validator::validate($input, [
            'order_id' => 'required|int|min:1',
            'payment_method' => 'required|string|min:2|max:40',
            'phone_number' => 'required|phone_tz',
        ]);
    }

    private function normalizeIdempotencyKey(string $idempotency_key): string
    {
        $key = trim($idempotency_key);
        if (strlen($key) < 8 || strlen($key) > 100 || !preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            throw ApiException::validation(['Idempotency-Key' => 'Tumia kitambulisho halali cha ombi la malipo.']);
        }
        return $key;
    }

    private function findExistingAttempt(int $user_id, int $order_id, string $key): ?int
    {
        $payment = $this->db->fetchOne(
            'SELECT payment_id, user_id, order_id FROM payments WHERE payment_idempotency_key = :key', ['key' => $key]
        );
        if ($payment === null) {
            return null;
        }
        if ((int) $payment['user_id'] !== $user_id || (int) $payment['order_id'] !== $order_id) {
            throw ApiException::conflict('IDEMPOTENCY_KEY_REUSED', 'Kitambulisho hiki cha ombi kimetumika kwa malipo mengine.');
        }
        return (int) $payment['payment_id'];
    }

    private function createAttempt(int $user_id, array $data, string $key): int
    {
        return $this->db->transaction(
            fn (Database $db): int => $this->createAttemptWithinTransaction($db, $user_id, $data, $key)
        );
    }

    private function createAttemptWithinTransaction(Database $db, int $user_id, array $data, string $key): int
    {
        $order = $this->lockOwnedOrder($db, $user_id, $data['order_id']);
        $this->validateMethod($db, $data['payment_method'], $order['order_payment_method']);
        $this->validateOrderState($db, $order);
        $amount = $this->outstandingBalance($order);
        if ($amount < 1) {
            throw ApiException::conflict('NO_BALANCE_DUE', 'Hakuna salio la kulipa kwenye oda hii.');
        }
        // Readiness runs after ownership checks and before any attempt row is inserted.
        $this->gateway->assertReady();
        return $this->insertAttempt($db, $user_id, $data, $key, $amount);
    }

    private function lockOwnedOrder(Database $db, int $user_id, int $order_id): array
    {
        $order = $db->fetchOne(
            'SELECT o.order_id, o.user_id, o.order_status, o.order_payment_method, o.order_payment_status,
                    o.order_total, o.order_refunded_amount,
                    (SELECT COALESCE(SUM(paid.payment_amount), 0) FROM payments paid
                     WHERE paid.order_id = o.order_id AND paid.payment_status = \'confirmed\') AS amount_paid
             FROM orders o WHERE o.order_id = :order_id FOR UPDATE',
            ['order_id' => $order_id]
        );
        if ($order === null || (int) $order['user_id'] !== $user_id) {
            throw ApiException::notFound('Oda haikupatikana.');
        }
        return $order;
    }

    private function validateMethod(Database $db, string $method_code, string $order_method): void
    {
        if ($order_method !== $method_code) {
            throw ApiException::validation(['payment_method' => 'Chagua njia ya malipo iliyowekwa kwenye oda hii.']);
        }
        $methods = new PaymentMethod($db);
        $method = $methods->getDetailsByCode($method_code);
        if ($method === null || !($method['payment_method_is_available'] ?? false)
            || $method['payment_method_type'] !== 'mobile_money'
            || !in_array($method_code, $methods->getActiveCodes(), true)) {
            throw ApiException::validation(['payment_method' => 'Njia hii ya malipo haipatikani kwa sasa.']);
        }
    }

    private function validateOrderState(Database $db, array $order): void
    {
        $is_initial = $order['order_status'] === 'pending_payment' && $order['order_payment_status'] === 'unpaid';
        $is_balance = $order['order_status'] === 'confirmed' && $order['order_payment_status'] === 'balance_due';
        if (!$is_initial && !$is_balance) {
            throw ApiException::conflict('PAYMENT_NOT_EXPECTED', 'Oda hii haisubiri malipo kwa simu.');
        }
        $pending = $db->fetchValue(
            "SELECT 1 FROM payments WHERE order_id = :order_id AND payment_provider = :provider
             AND payment_status IN ('initiated', 'pending') LIMIT 1",
            ['order_id' => $order['order_id'], 'provider' => self::PROVIDER]
        );
        if ($pending) {
            throw ApiException::conflict('PAYMENT_ALREADY_PENDING', 'Ombi la malipo ya oda hii linaendelea.');
        }
    }

    private function outstandingBalance(array $order): int
    {
        $paid = max(0, (int) $order['amount_paid'] - (int) $order['order_refunded_amount']);
        return max(0, (int) $order['order_total'] - $paid);
    }

    private function insertAttempt(Database $db, int $user_id, array $data, string $key, int $amount): int
    {
        $reference = 'CHP' . strtoupper(bin2hex(random_bytes(12)));
        $payment_id = $db->insert(
            "INSERT INTO payments (order_id, user_id, payment_method_code, payment_amount, payment_payer_account,
                                  payment_reference, payment_status, payment_provider, payment_idempotency_key)
             VALUES (:order_id, :user_id, :method, :amount, :phone, :reference, 'initiated', :provider, :idempotency_key)",
            ['order_id' => $data['order_id'], 'user_id' => $user_id, 'method' => $data['payment_method'],
             'amount' => $amount, 'phone' => $data['phone_number'], 'reference' => $reference,
             'provider' => self::PROVIDER, 'idempotency_key' => $key]
        );
        $db->execute("UPDATE orders SET order_payment_status = 'pending' WHERE order_id = :order_id",
            ['order_id' => $data['order_id']]);
        return $payment_id;
    }

    private function getAttemptForProvider(int $payment_id): array
    {
        return $this->db->fetchOne(
            'SELECT payment_id, order_id, payment_amount, payment_method_code, payment_payer_account, payment_reference
             FROM payments WHERE payment_id = :payment_id',
            ['payment_id' => $payment_id]
        );
    }

    private function sendInitiationRequest(array $payment, string $key): array
    {
        try {
            $response = $this->gateway->initiatePayment($payment + ['payment_idempotency_key' => $key]);
            return $this->gateway->interpretResponse($response);
        } catch (Throwable $exception) {
            $this->finishFailedInitiation((int) $payment['payment_id']);
            if ($exception instanceof ApiException) {
                throw $exception;
            }
            Logger::exception($exception, ['payment_id' => $payment['payment_id'], 'provider' => self::PROVIDER]);
            throw new ApiException(503, 'PAYMENT_PROVIDER_UNAVAILABLE', 'Huduma ya malipo haipatikani kwa sasa. Jaribu tena baadaye.');
        }
    }

    private function saveInitiationResult(array $payment, array $normalized): void
    {
        $provider_status = $normalized['payment_status'] ?? 'pending';
        $status = in_array($provider_status, ['failed', 'cancelled', 'expired'], true) ? $provider_status : 'pending';
        $provider_reference = $this->gateway->getProviderReference($normalized);
        $this->db->transaction(function (Database $db) use ($payment, $normalized, $provider_reference, $status): void {
            $db->execute(
                'UPDATE payments SET payment_status = :status, payment_provider_reference = :provider_reference,
                        payment_provider_redirect_url = :redirect_url WHERE payment_id = :payment_id AND payment_status = \'initiated\'',
                ['status' => $status, 'provider_reference' => $provider_reference,
                 'redirect_url' => $normalized['redirect_url'] ?? null, 'payment_id' => $payment['payment_id']]
            );
            $this->updateOrderPaymentStatus($db, (int) $payment['order_id'], $status);
        });
    }

    private function updateOrderPaymentStatus(Database $db, int $order_id, string $payment_status): void
    {
        $order_status = in_array($payment_status, ['failed', 'cancelled', 'expired'], true)
            ? (($db->fetchValue('SELECT order_status FROM orders WHERE order_id = :id', ['id' => $order_id]) === 'confirmed') ? 'balance_due' : 'unpaid')
            : 'pending';
        $db->execute('UPDATE orders SET order_payment_status = :status WHERE order_id = :order_id',
            ['status' => $order_status, 'order_id' => $order_id]);
    }

    private function finishFailedInitiation(int $payment_id): void
    {
        $this->db->transaction(function (Database $db) use ($payment_id): void {
            $payment = $db->fetchOne('SELECT order_id FROM payments WHERE payment_id = :id FOR UPDATE', ['id' => $payment_id]);
            if ($payment === null) {
                return;
            }
            $order = $db->fetchOne('SELECT order_status FROM orders WHERE order_id = :id FOR UPDATE', ['id' => $payment['order_id']]);
            $db->execute("UPDATE payments SET payment_status = 'failed' WHERE payment_id = :id AND payment_status = 'initiated'", ['id' => $payment_id]);
            if ($order !== null) {
                $db->execute('UPDATE orders SET order_payment_status = :status WHERE order_id = :id AND order_payment_status = \'pending\'',
                    ['status' => $order['order_status'] === 'confirmed' ? 'balance_due' : 'unpaid', 'id' => $payment['order_id']]);
            }
        });
    }
}
