<?php

/**
 * Payments for mobile money and bank orders, checked by staff until a payment provider is connected.
 *
 *   1. The customer orders (status "pending_payment", payment "unpaid") and pays with the "pay to" details.
 *   2. "Nimelipa": submitPayment() saves the number they paid from + the confirmation code → payment "pending".
 *   3. Staff compare it with the M-Pesa / bank statement:
 *        confirmPayment() → payment "paid", order "confirmed" (stock was already reserved)
 *        rejectPayment()  → payment "unpaid" again, the customer is told why and can send it again
 *   Phone orders: recordPaymentByStaff() saves and confirms in one step.
 *   Paid orders that are later cancelled appear in getRefundsDue() until markRefunded().
 *
 * Only staff ever mark an order paid — a code typed by a customer is never trusted on its own.
 * Later the provider's callback will fill the same rows and confirm them automatically.
 */
class Payment
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];
    private const DEFAULT_PER_PAGE = 25;

    private Order $order_model;

    public function __construct(private Database $db)
    {
        $this->order_model = new Order($db);
    }

    // ------------------------------------------------------------------ customer

    /**
     * "Nimelipa". Body: {"payment_payer_account": "0712345678" (or the bank account), "payment_reference": "QJK3X7ABC1"}.
     * Returns the updated order.
     */
    public function submitPayment(int $user_id, int $order_id, array $input): array
    {
        $this->db->transaction(function () use ($user_id, $order_id, $input) {
            $order = $this->lockOrderForPayment($order_id, $user_id);

            match (true) {
                $order['order_payment_method'] === 'cod' || $order['order_status'] !== 'pending_payment'
                    => throw ApiException::conflict('PAYMENT_NOT_EXPECTED', 'Oda hii haisubiri malipo.'),
                $order['order_payment_status'] === 'pending'
                    => throw ApiException::conflict('PAYMENT_UNDER_REVIEW', 'Tumepokea taarifa za malipo yako na tunazikagua. Tutakujulisha punde.'),
                (bool) $order['is_past_payment_time']
                    => throw ApiException::conflict('PAYMENT_TIME_OVER', 'Muda wa kulipia oda hii umeisha. Kama umeshalipa, wasiliana na msaada.'),
                default => null,
            };

            $details = $this->checkPaymentDetails($order['order_payment_method'], $input);
            $this->savePayment($order, $details, null);
        });

        return $this->order_model->getOrder($user_id, $order_id);
    }

    /**
     * "Badilisha njia ya malipo" on an order that is still waiting for payment (nothing sent, or the last one rejected).
     * Body: {"payment_method": "airtel_money"}. Switching to "cod" (Lipa ukipokea) uses the same cash limit as checkout
     * and confirms the order straight away, as if cash had been chosen at checkout. The time to pay does not restart.
     * Returns the updated order.
     */
    public function changePaymentMethod(int $user_id, int $order_id, array $input): array
    {
        $data = Validator::validate($input, ['payment_method' => 'required|in:mpesa,airtel_money,mixx,bank,cod']);

        $this->db->transaction(function (Database $db) use ($user_id, $order_id, $data) {
            $order = $this->lockOrderForPayment($order_id, $user_id);

            match (true) {
                $order['order_payment_status'] === 'pending'
                    => throw ApiException::conflict('PAYMENT_UNDER_REVIEW', 'Malipo yako yanakaguliwa, kwa hiyo njia ya malipo haiwezi kubadilishwa sasa.'),
                $order['order_payment_method'] === 'cod' || $order['order_status'] !== 'pending_payment' || $order['order_payment_status'] !== 'unpaid'
                    => throw ApiException::conflict('PAYMENT_METHOD_LOCKED', 'Njia ya malipo ya oda hii haiwezi kubadilishwa. Wasiliana na msaada.'),
                (bool) $order['is_past_payment_time']
                    => throw ApiException::conflict('PAYMENT_TIME_OVER', 'Muda wa kulipia oda hii umeisha.'),
                default => null,
            };

            $new_method = $data['payment_method'];
            if ($new_method === $order['order_payment_method']) {
                return;   // nothing to change
            }
            if (!in_array($new_method, (new PaymentMethod($db))->getActiveCodes(), true)) {
                throw ApiException::validation(['payment_method' => 'Njia hii ya malipo haipatikani kwa sasa.']);
            }

            if ($new_method === 'cod') {
                $this->order_model->checkCashOnDeliveryLimit('cod', (int) $order['order_total']);
                $db->execute(
                    "UPDATE orders SET order_payment_method = 'cod', order_payment_status = 'cod_pending', order_expires_at = NULL
                     WHERE order_id = :order_id",
                    ['order_id' => $order_id]
                );
                $order['order_payment_status'] = 'cod_pending';
                $this->order_model->moveToStatus($order, 'confirmed', 'customer', $user_id, 'Utalipa ukipokea mzigo');
            } else {
                $db->execute('UPDATE orders SET order_payment_method = :payment_method WHERE order_id = :order_id',
                    ['payment_method' => $new_method, 'order_id' => $order_id]);
            }

            (new AuditLog($db))->record('customer', $user_id, 'order.payment_method_changed', 'order', $order_id,
                ['order_payment_method' => $order['order_payment_method']], ['order_payment_method' => $new_method]);
        });

        return $this->order_model->getOrder($user_id, $order_id);
    }

    // ------------------------------------------------------------------ staff

    /**
     * The Payments page list, newest first. Filters (all optional): payment_status (submitted / confirmed / rejected),
     * payment_method_code, search (order number, confirmation code or payer number), page, per_page (25/50/100).
     */
    public function searchPayments(array $filters): array
    {
        $data = Validator::validate($filters, [
            'payment_status'      => 'nullable|in:submitted,confirmed,rejected',
            'payment_method_code' => 'nullable|in:mpesa,airtel_money,mixx,bank,cod',
            'search'              => 'nullable|string|max:60',
            'page'                => 'nullable|int|min:1',
            'per_page'            => 'nullable|int|in:' . implode(',', self::PER_PAGE_OPTIONS),
        ]);
        $per_page = $data['per_page'] ?? self::DEFAULT_PER_PAGE;

        $conditions = ['1 = 1'];
        $params     = [];
        foreach (['payment_status', 'payment_method_code'] as $column) {
            if (isset($data[$column])) {
                $conditions[]    = "p.{$column} = :{$column}";
                $params[$column] = $data[$column];
            }
        }
        if (isset($data['search'])) {
            $conditions[] = '(o.order_number = :search_order OR p.payment_reference = :search_reference OR p.payment_payer_account LIKE :search_payer)';
            $params['search_order']     = strtoupper(trim($data['search']));
            $params['search_reference'] = $this->cleanReference($data['search']);
            // "0712 000 111", "255712000111" and "+255712000111" all find +255712000111
            $digits = preg_replace('/^(255|0)/', '', (string) preg_replace('/\D/', '', $data['search']));
            $params['search_payer']     = '%' . addcslashes($digits !== '' ? $digits : $data['search'], '%_\\') . '%';
        }
        $where_sql = implode(' AND ', $conditions);

        $total     = (int) $this->db->fetchValue("SELECT COUNT(*) FROM payments p JOIN orders o ON o.order_id = p.order_id WHERE {$where_sql}", $params);
        $last_page = max(1, (int) ceil($total / $per_page));
        $page      = min($data['page'] ?? 1, $last_page);

        $items = $this->db->fetchAll(
            "SELECT p.*, o.order_number, o.order_total, o.order_status, o.order_payment_status, o.order_expires_at,
                    u.user_full_name, u.user_phone, b.business_name,
                    reviewer.admin_full_name AS reviewed_by_admin_name, submitter.admin_full_name AS submitted_by_admin_name
             FROM payments p
             JOIN orders o ON o.order_id = p.order_id
             JOIN users u  ON u.user_id = p.user_id
             LEFT JOIN business_profiles b ON b.user_id = p.user_id
             LEFT JOIN admins reviewer  ON reviewer.admin_id = p.reviewed_by_admin_id
             LEFT JOIN admins submitter ON submitter.admin_id = p.submitted_by_admin_id
             WHERE {$where_sql}
             ORDER BY p.payment_id DESC
             LIMIT {$per_page} OFFSET " . (($page - 1) * $per_page),
            $params
        );

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page];
    }

    /** How many payments are waiting for staff (menu badge, dashboard). */
    public function countWaitingForReview(): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM payments WHERE payment_status = 'submitted'");
    }

    /** Every payment of one order, newest first (the admin order page). */
    public function getPaymentsForOrder(int $order_id): array
    {
        return $this->db->fetchAll(
            'SELECT p.*, reviewer.admin_full_name AS reviewed_by_admin_name
             FROM payments p LEFT JOIN admins reviewer ON reviewer.admin_id = p.reviewed_by_admin_id
             WHERE p.order_id = :order_id ORDER BY p.payment_id DESC',
            ['order_id' => $order_id]
        );
    }

    /** "Confirm": the money is on the statement. The order becomes paid and confirmed; the customer is told. */
    public function confirmPayment(int $payment_id, int $admin_id): void
    {
        $this->db->transaction(function () use ($payment_id, $admin_id) {
            $payment = $this->lockSubmittedPayment($payment_id);
            $order   = $this->lockOrderForPayment((int) $payment['order_id'], null);
            $this->confirmLockedPayment($payment, $order, $admin_id);
        });
    }

    /** "Reject" with the reason the customer will see, e.g. "Hatukupata malipo haya kwenye taarifa yetu." */
    public function rejectPayment(int $payment_id, array $input, int $admin_id): void
    {
        $data = Validator::validate($input, ['payment_review_note' => 'required|string|min:5|max:255']);

        $this->db->transaction(function (Database $db) use ($payment_id, $data, $admin_id) {
            $payment = $this->lockSubmittedPayment($payment_id);
            $order   = $this->lockOrderForPayment((int) $payment['order_id'], null);

            $db->execute(
                "UPDATE payments SET payment_status = 'rejected', payment_review_note = :note,
                        reviewed_by_admin_id = :admin_id, payment_reviewed_at = UTC_TIMESTAMP()
                 WHERE payment_id = :payment_id",
                ['note' => $data['payment_review_note'], 'admin_id' => $admin_id, 'payment_id' => $payment_id]
            );
            $db->execute(
                "UPDATE orders SET order_payment_status = 'unpaid' WHERE order_id = :order_id AND order_payment_status = 'pending'",
                ['order_id' => $order['order_id']]
            );

            (new Notification($db))->notifyUser((int) $order['user_id'], 'payment', 'Malipo hayajathibitishwa',
                "Hatukuweza kuthibitisha malipo ya oda #{$order['order_number']}: {$data['payment_review_note']} Tafadhali hakiki na utume tena.",
                ['order_id' => (int) $order['order_id']]);
            (new AuditLog($db))->record('admin', $admin_id, 'payment.rejected', 'payment', $payment_id, null,
                ['order_id' => (int) $order['order_id'], 'payment_review_note' => $data['payment_review_note']]);
        });
    }

    /**
     * Phone orders: staff saw the money arrive for an unpaid order and record it themselves (saved and confirmed at once).
     * Body: {"payment_payer_account", "payment_reference"}.
     */
    public function recordPaymentByStaff(int $order_id, array $input, int $admin_id): void
    {
        $this->db->transaction(function () use ($order_id, $input, $admin_id) {
            $order = $this->lockOrderForPayment($order_id, null);
            if ($order['order_payment_method'] === 'cod' || $order['order_status'] !== 'pending_payment' || $order['order_payment_status'] !== 'unpaid') {
                throw ApiException::conflict('PAYMENT_NOT_EXPECTED', 'Only an unpaid mobile money or bank order with no payment under review can be recorded.');
            }

            $details    = $this->checkPaymentDetails($order['order_payment_method'], $input);
            $payment_id = $this->savePayment($order, $details, $admin_id);
            $this->confirmLockedPayment($this->lockSubmittedPayment($payment_id), $this->lockOrderForPayment($order_id, null), $admin_id);
        });
    }

    /** Paid orders that were cancelled or expired: the money must go back to the customer. */
    public function getRefundsDue(): array
    {
        return $this->db->fetchAll(
            "SELECT o.order_id, o.order_number, o.order_total, o.order_payment_method, o.order_status, o.order_cancelled_at,
                    o.order_cancel_reason, u.user_full_name, u.user_phone,
                    (SELECT p.payment_payer_account FROM payments p
                     WHERE p.order_id = o.order_id AND p.payment_status = 'confirmed' ORDER BY p.payment_id DESC LIMIT 1) AS payment_payer_account
             FROM orders o
             JOIN users u ON u.user_id = o.user_id
             WHERE o.order_status IN ('cancelled', 'expired') AND o.order_payment_status = 'paid'
             ORDER BY o.order_cancelled_at"
        );
    }

    /** "Mark refunded" once the money has been sent back. Body: {"refund_note": "M-Pesa QJK… 45,000 to 0712…"}. */
    public function markRefunded(int $order_id, array $input, int $admin_id): void
    {
        $data = Validator::validate($input, ['refund_note' => 'required|string|min:5|max:255']);

        $this->db->transaction(function (Database $db) use ($order_id, $data, $admin_id) {
            $order = $this->lockOrderForPayment($order_id, null);
            if (!in_array($order['order_status'], ['cancelled', 'expired'], true) || $order['order_payment_status'] !== 'paid') {
                throw ApiException::conflict('REFUND_NOT_DUE', 'Only a paid order that was cancelled can be marked refunded.');
            }

            $db->execute("UPDATE orders SET order_payment_status = 'refunded' WHERE order_id = :order_id", ['order_id' => $order_id]);
            (new Notification($db))->notifyUser((int) $order['user_id'], 'payment', 'Pesa imerudishwa',
                "Malipo ya oda #{$order['order_number']} (TZS " . number_format((int) $order['order_total']) . ') yamerudishwa kwako.',
                ['order_id' => $order_id]);
            (new AuditLog($db))->record('admin', $admin_id, 'order.refunded', 'order', $order_id, ['order_payment_status' => 'paid'],
                ['order_payment_status' => 'refunded', 'refund_note' => $data['refund_note']]);
        });
    }

    // ------------------------------------------------------------------ used by Order (order JSON)

    /** The newest payment of each order: [order_id => row] (one query for a whole page of orders). */
    public function getLatestPaymentsForOrders(array $order_ids): array
    {
        $order_ids = array_map('intval', $order_ids);
        if ($order_ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT p.* FROM payments p
             JOIN (SELECT order_id, MAX(payment_id) AS payment_id FROM payments
                   WHERE order_id IN (' . implode(', ', $order_ids) . ') GROUP BY order_id) newest
               ON newest.payment_id = p.payment_id'
        ); // the ids were converted to whole numbers above, so they are safe in the SQL

        return array_column($rows, null, 'order_id');
    }

    // ------------------------------------------------------------------ private

    /**
     * The payer number/account and the confirmation code, cleaned:
     * mobile money → the payer must be a Tanzanian mobile number; the code is kept as capital letters and digits.
     */
    private function checkPaymentDetails(string $payment_method_code, array $input): array
    {
        $is_bank = $payment_method_code === 'bank';
        $data = Validator::validate($input, [
            'payment_payer_account' => $is_bank ? 'required|string|min:4|max:40' : 'required|phone_tz',
            'payment_reference'     => 'required|string|max:40',
        ]);

        $reference = $this->cleanReference($data['payment_reference']);
        if (strlen($reference) < 6 || strlen($reference) > 30) {
            throw ApiException::validation(['payment_reference' => 'Andika namba ya muamala kama ilivyo kwenye ujumbe wa malipo (herufi na namba 6 hadi 30).']);
        }

        // One payment can't be used for two orders
        $used = $this->db->fetchValue(
            "SELECT 1 FROM payments
             WHERE payment_method_code = :payment_method_code AND payment_reference = :payment_reference AND payment_status <> 'rejected'",
            ['payment_method_code' => $payment_method_code, 'payment_reference' => $reference]
        );
        if ($used) {
            throw ApiException::validation(['payment_reference' => 'Namba hii ya muamala imeshatumika.']);
        }

        return ['payment_payer_account' => $data['payment_payer_account'], 'payment_reference' => $reference];
    }

    /** "qjk3x7 abc1" → "QJK3X7ABC1". */
    private function cleanReference(string $reference): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($reference));
    }

    /** Saves a submitted payment and marks the order "pending" (waiting for review). Returns the payment id. */
    private function savePayment(array $order, array $details, ?int $admin_id): int
    {
        $payment_id = $this->db->insert(
            'INSERT INTO payments (order_id, user_id, payment_method_code, payment_amount, payment_payer_account,
                                   payment_reference, submitted_by_admin_id)
             VALUES (:order_id, :user_id, :payment_method_code, :payment_amount, :payment_payer_account,
                     :payment_reference, :admin_id)',
            [
                'order_id'            => $order['order_id'],
                'user_id'             => $order['user_id'],
                'payment_method_code' => $order['order_payment_method'],
                'payment_amount'      => $order['order_total'],
                'admin_id'            => $admin_id,
            ] + $details
        );
        $this->db->execute("UPDATE orders SET order_payment_status = 'pending' WHERE order_id = :order_id", ['order_id' => $order['order_id']]);
        return $payment_id;
    }

    /** The shared "confirm" work (payment row and order already locked). */
    private function confirmLockedPayment(array $payment, array $order, int $admin_id): void
    {
        if ($order['order_status'] !== 'pending_payment') {
            throw ApiException::conflict('ORDER_NOT_WAITING_FOR_PAYMENT',
                "This order is \"{$order['order_status']}\", so it can't be confirmed. Reject the payment and refund the customer if the money arrived.");
        }
        $already_confirmed = $this->db->fetchValue(
            "SELECT 1 FROM payments WHERE payment_method_code = :payment_method_code AND payment_reference = :payment_reference
               AND payment_status = 'confirmed' AND payment_id <> :payment_id",
            ['payment_method_code' => $payment['payment_method_code'], 'payment_reference' => $payment['payment_reference'], 'payment_id' => $payment['payment_id']]
        );
        if ($already_confirmed) {
            throw ApiException::conflict('PAYMENT_REFERENCE_USED', 'This confirmation code was already confirmed for another order.');
        }

        $this->db->execute(
            "UPDATE payments SET payment_status = 'confirmed', reviewed_by_admin_id = :admin_id, payment_reviewed_at = UTC_TIMESTAMP()
             WHERE payment_id = :payment_id",
            ['admin_id' => $admin_id, 'payment_id' => $payment['payment_id']]
        );
        $this->db->execute(
            "UPDATE orders SET order_payment_status = 'paid', order_expires_at = NULL WHERE order_id = :order_id",
            ['order_id' => $order['order_id']]
        );
        $order['order_payment_status'] = 'paid';
        $this->order_model->moveToStatus($order, 'confirmed', 'admin', $admin_id, 'Malipo yamethibitishwa');

        (new AuditLog($this->db))->record('admin', $admin_id, 'payment.confirmed', 'payment', (int) $payment['payment_id'], null,
            ['order_id' => (int) $order['order_id'], 'payment_amount' => (int) $payment['payment_amount'], 'payment_reference' => $payment['payment_reference']]);
    }

    /** The order row locked for the payment change; $user_id limits it to that customer's orders (404 otherwise). */
    private function lockOrderForPayment(int $order_id, ?int $user_id): array
    {
        $order = $this->db->fetchOne(
            'SELECT order_id, order_number, user_id, order_status, order_payment_method, order_payment_status, order_total,
                    order_expires_at IS NOT NULL AND order_expires_at < UTC_TIMESTAMP() AS is_past_payment_time
             FROM orders WHERE order_id = :order_id FOR UPDATE',
            ['order_id' => $order_id]
        );
        if ($order === null || ($user_id !== null && (int) $order['user_id'] !== $user_id)) {
            throw ApiException::notFound($user_id === null ? 'Order not found.' : 'Oda haikupatikana.');
        }
        return $order;
    }

    private function lockSubmittedPayment(int $payment_id): array
    {
        $payment = $this->db->fetchOne('SELECT * FROM payments WHERE payment_id = :payment_id FOR UPDATE', ['payment_id' => $payment_id]);
        if ($payment === null) {
            throw ApiException::notFound('Payment not found.');
        }
        if ($payment['payment_status'] !== 'submitted') {
            throw ApiException::conflict('PAYMENT_ALREADY_REVIEWED', "This payment was already {$payment['payment_status']}.");
        }
        return $payment;
    }
}
