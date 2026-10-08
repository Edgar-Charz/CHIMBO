<?php

/**
 * The payment methods customers can choose (M-Pesa, Airtel Money, Mixx, Benki, Lipa ukipokea)
 * and the "pay to" details staff enter for each (Lipa Namba / account number, account name, instructions).
 *
 * How to use it:
 *   $payment_method_model = new PaymentMethod(Database::instance());
 *   $payment_method_model->getActiveMethods();                         // checkout + order page (public shape)
 *   $payment_method_model->updateMethod($payment_method_id, $_POST, $admin_id);   // admin
 */
class PaymentMethod
{
    private const RULES = [
        'payment_method_name'           => 'required|string|min:2|max:60',
        'payment_method_account_name'   => 'nullable|string|max:100',
        'payment_method_account_number' => 'nullable|string|max:40',
        'payment_method_bank_name'      => 'nullable|string|max:80',
        'payment_method_instructions'   => 'nullable|string|max:500',
        'payment_method_is_active'      => 'nullable|bool',      // checkbox
        'payment_method_sort_order'     => 'nullable|int|min:0|max:1000',
    ];

    // All methods, read once per request (the table is tiny)
    private static ?array $cached_methods = null;

    public function __construct(private Database $db)
    {
    }

    /** Every method (on and off), in display order — the admin list. */
    public function listForAdmin(): array
    {
        return array_values($this->allMethods());
    }

    /** One method for the edit form, or 404. */
    public function getMethodById(int $payment_method_id): array
    {
        foreach ($this->allMethods() as $method) {
            if ((int) $method['payment_method_id'] === $payment_method_id) {
                return $method;
            }
        }
        throw ApiException::notFound('Payment method not found.');
    }

    /** The codes customers may choose now, e.g. ['mpesa', 'cod']. */
    public function getActiveCodes(): array
    {
        return array_column($this->getActiveMethods(), 'payment_method_code');
    }

    /** The switched-on methods with their "pay to" details, as the apps show them. */
    public function getActiveMethods(): array
    {
        $active = array_filter($this->allMethods(), fn (array $method) => (bool) $method['payment_method_is_active']);
        return array_values(array_map(fn (array $method) => $this->formatMethod($method), $active));
    }

    /** One method's public details by code (also for switched-off ones, e.g. an older order). Null if unknown. */
    public function getDetailsByCode(string $payment_method_code): ?array
    {
        $method = $this->allMethods()[$payment_method_code] ?? null;
        return $method === null ? null : $this->formatMethod($method);
    }

    /** Saves the edit form. Switching on a mobile money or bank method needs its account number and name. */
    public function updateMethod(int $payment_method_id, array $input, int $admin_id): void
    {
        $old  = $this->getMethodById($payment_method_id);
        $data = Validator::validate($input, self::RULES);

        $values = [
            'payment_method_name'           => $data['payment_method_name'],
            'payment_method_account_name'   => $data['payment_method_account_name'] ?? null,
            'payment_method_account_number' => $data['payment_method_account_number'] ?? null,
            'payment_method_bank_name'      => $data['payment_method_bank_name'] ?? null,
            'payment_method_instructions'   => $data['payment_method_instructions'] ?? null,
            'payment_method_is_active'      => (int) ($data['payment_method_is_active'] ?? false),
            'payment_method_sort_order'     => $data['payment_method_sort_order'] ?? 0,
        ];
        if ($values['payment_method_is_active']) {
            $this->checkReadyToSwitchOn($values + ['payment_method_type' => $old['payment_method_type']]);
        }

        $this->db->execute(
            'UPDATE payment_methods
             SET payment_method_name = :payment_method_name, payment_method_account_name = :payment_method_account_name,
                 payment_method_account_number = :payment_method_account_number, payment_method_bank_name = :payment_method_bank_name,
                 payment_method_instructions = :payment_method_instructions, payment_method_is_active = :payment_method_is_active,
                 payment_method_sort_order = :payment_method_sort_order
             WHERE payment_method_id = :payment_method_id',
            $values + ['payment_method_id' => $payment_method_id]
        );
        self::$cached_methods = null;

        (new AuditLog($this->db))->record('admin', $admin_id, 'payment_method.updated', 'payment_method', $payment_method_id,
            array_intersect_key($old, $values), $values);
    }

    /** The one-click "Offer at checkout / Stop offering" switch. Returns false when nothing changed. */
    public function setPaymentMethodActive(int $payment_method_id, bool $is_active, int $admin_id): bool
    {
        if ($is_active) {
            $this->checkReadyToSwitchOn($this->getMethodById($payment_method_id));
        }
        $changed = (new RecordSwitch($this->db))->set('payment_methods', 'payment_method_id', $payment_method_id, 'payment_method_is_active', (int) $is_active, $admin_id, 'payment_method');
        self::$cached_methods = null;
        return $changed;
    }

    /** Forgets the cached list (used by tests). */
    public static function clearCache(): void
    {
        self::$cached_methods = null;
    }

    /** Customers can't pay a method that has no account to pay to. */
    private function checkReadyToSwitchOn(array $method): void
    {
        if ($method['payment_method_type'] === 'cash') {
            return;
        }
        $missing = [];
        if (trim((string) $method['payment_method_account_number']) === '') {
            $missing['payment_method_account_number'] = 'Enter the Lipa Namba or account number before offering this method.';
        }
        if (trim((string) $method['payment_method_account_name']) === '') {
            $missing['payment_method_account_name'] = 'Enter the account name customers will see when paying.';
        }
        if ($method['payment_method_type'] === 'bank' && trim((string) $method['payment_method_bank_name']) === '') {
            $missing['payment_method_bank_name'] = 'Enter the bank name.';
        }
        if ($missing !== []) {
            throw ApiException::validation($missing);
        }
    }

    /** [code => row], in display order. */
    private function allMethods(): array
    {
        if (self::$cached_methods === null) {
            $rows = $this->db->fetchAll('SELECT * FROM payment_methods ORDER BY payment_method_sort_order, payment_method_id');
            self::$cached_methods = array_column($rows, null, 'payment_method_code');
        }
        return self::$cached_methods;
    }

    /** A row → what the apps show at checkout and on an unpaid order. */
    private function formatMethod(array $method): array
    {
        return [
            'payment_method_code'           => $method['payment_method_code'],
            'payment_method_name'           => $method['payment_method_name'],
            'payment_method_type'           => $method['payment_method_type'],   // mobile_money | bank | cash
            'payment_method_account_name'   => $method['payment_method_account_name'],
            'payment_method_account_number' => $method['payment_method_account_number'],
            'payment_method_bank_name'      => $method['payment_method_bank_name'],
            'payment_method_instructions'   => $method['payment_method_instructions'],
        ];
    }
}
