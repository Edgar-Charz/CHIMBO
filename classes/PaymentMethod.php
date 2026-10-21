<?php

/**
 * The payment methods customers can choose (M-Pesa, Airtel Money, Mixx, Benki, Lipa ukipokea, and any mobile money or
 * bank method staff add) and the "pay to" details staff enter for each (Lipa Namba / account number, account name,
 * instructions). What a method does comes from its type: mobile money / bank (pay first, staff check it) or cash
 * (the one built-in "Lipa ukipokea" — staff can't add another).
 *
 * How to use it:
 *   $payment_method_model = new PaymentMethod(Database::instance());
 *   $payment_method_model->getActiveMethods();                         // checkout + order page (public shape)
 *   $payment_method_model->createMethod($_POST, $admin_id);            // admin: a new mobile money or bank method
 *   $payment_method_model->updateMethod($payment_method_id, $_POST, $admin_id);   // admin
 *   $payment_method_model->nameOf('mpesa');                            // "M-Pesa" (lists, receipts)
 */
class PaymentMethod
{
    private const ICON_SIZES = ['128' => 128];

    private const RULES = [
        'payment_method_name'           => 'required|string|min:2|max:60',
        'payment_method_account_name'   => 'nullable|string|max:100',
        'payment_method_account_number' => 'nullable|string|max:40',
        'payment_method_bank_name'      => 'nullable|string|max:80',
        'payment_method_instructions'   => 'nullable|string|max:500',
        'payment_method_is_active'      => 'nullable|bool',      // checkbox
        'payment_method_sort_order'     => 'nullable|int|min:0|max:1000',
    ];

    // The types staff can add (cash on delivery is built in and only once)
    public const ADDABLE_TYPES = ['mobile_money' => 'Mobile money', 'bank' => 'Bank'];

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

    /** One method's public details by code; old orders with removed methods receive a safe unavailable placeholder. */
    public function getDetailsByCode(string $payment_method_code): ?array
    {
        $method = $this->allMethods()[$payment_method_code] ?? null;
        if ($method === null) {
            return [
                'payment_method_code' => $payment_method_code,
                'payment_method_name' => 'Njia ya malipo isiyopatikana',
                'payment_method_type' => 'mobile_money',
                'payment_method_account_name' => null,
                'payment_method_account_number' => null,
                'payment_method_bank_name' => null,
                'payment_method_instructions' => null,
                'payment_method_icon_url' => null,
                'payment_method_is_available' => false,
            ];
        }
        return $method === null ? null : $this->formatMethod($method);
    }

    /** The name customers see for a code ("mpesa" → "M-Pesa"), also for switched-off methods; the code if unknown. */
    public function nameOf(string $payment_method_code): string
    {
        $method = $this->allMethods()[$payment_method_code] ?? null;
        return $method['payment_method_name'] ?? 'Njia ya malipo isiyopatikana';
    }

    /**
     * Adds a mobile money or bank method (e.g. "HaloPesa", "NMB Bank"). Its code — what orders store — is made from the
     * name ("halopesa", "nmb-bank") and never changes. Returns the new payment_method_id.
     */
    public function createMethod(array $input, int $admin_id, ?array $icon_upload = null): int
    {
        $data = Validator::validate($input, self::RULES + ['payment_method_type' => 'required|in:' . implode(',', array_keys(self::ADDABLE_TYPES))]);
        $values = $this->formValues($data) + ['payment_method_type' => $data['payment_method_type']];
        if ($values['payment_method_is_active']) {
            $this->checkReadyToSwitchOn($values);
        }
        $values['payment_method_code'] = Slug::unique($this->db, $values['payment_method_name'], 'payment_methods', 'payment_method_code', 'payment_method_id', null);

        $uploader = new ImageUploader();
        $icon_path = $this->saveOptionalUploadedIcon($icon_upload, $uploader);

        try {
            $payment_method_id = $this->db->insert(
                'INSERT INTO payment_methods (payment_method_code, payment_method_name, payment_method_type, payment_method_account_name,
                                          payment_method_account_number, payment_method_bank_name, payment_method_instructions,
                                          payment_method_icon_path, payment_method_is_active, payment_method_sort_order)
             VALUES (:payment_method_code, :payment_method_name, :payment_method_type, :payment_method_account_name,
                     :payment_method_account_number, :payment_method_bank_name, :payment_method_instructions,
                     :payment_method_icon_path, :payment_method_is_active, :payment_method_sort_order)',
                $values + ['payment_method_icon_path' => $icon_path]
            );
        } catch (Throwable $exception) {
            if ($icon_path !== null) {
                $uploader->deleteImageFiles([$icon_path]);
            }
            throw $exception;
        }
        self::$cached_methods = null;

        (new AuditLog($this->db))->record(
            'admin',
            $admin_id,
            'payment_method.created',
            'payment_method',
            $payment_method_id,
            null,
            $values + ['payment_method_icon_path' => $icon_path]
        );
        return $payment_method_id;
    }

    /** Saves the edit form. Switching on a mobile money or bank method needs its account number and name. */
    public function updateMethod(int $payment_method_id, array $input, int $admin_id): void
    {
        $old    = $this->getMethodById($payment_method_id);
        $values = $this->formValues(Validator::validate($input, self::RULES));
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

    /** Saves a customer-facing logo for this method. A transparent square PNG is generated for consistent display. */
    public function setIcon(int $payment_method_id, ?array $uploaded_file, int $admin_id): void
    {
        if ($uploaded_file === null || ($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($uploaded_file['tmp_name'] ?? '')) {
            throw ApiException::validation(['payment_method_icon' => 'Choose an icon to upload.']);
        }

        $this->saveIconFromFile($payment_method_id, $uploaded_file['tmp_name'], $admin_id);
    }

    /** Same as setIcon() for files already on the server (tests and scripts). */
    public function setIconFromFile(int $payment_method_id, string $file_path, int $admin_id): void
    {
        $this->saveIconFromFile($payment_method_id, $file_path, $admin_id);
    }

    /** Removes the uploaded logo so clients return to their built-in type icon. */
    public function removeIcon(int $payment_method_id, int $admin_id): void
    {
        $method = $this->getMethodById($payment_method_id);
        $old_path = $method['payment_method_icon_path'];
        if ($old_path === null) {
            return;
        }

        $this->db->execute(
            'UPDATE payment_methods SET payment_method_icon_path = NULL WHERE payment_method_id = :payment_method_id',
            ['payment_method_id' => $payment_method_id]
        );
        self::$cached_methods = null;
        (new ImageUploader())->deleteImageFiles([$old_path]);
        (new AuditLog($this->db))->record('admin', $admin_id, 'payment_method.icon_removed', 'payment_method', $payment_method_id);
    }

    /** The checked form → the column values (the type and code are set once, on creation). */
    private function formValues(array $data): array
    {
        return [
            'payment_method_name'           => $data['payment_method_name'],
            'payment_method_account_name'   => $data['payment_method_account_name'] ?? null,
            'payment_method_account_number' => $data['payment_method_account_number'] ?? null,
            'payment_method_bank_name'      => $data['payment_method_bank_name'] ?? null,
            'payment_method_instructions'   => $data['payment_method_instructions'] ?? null,
            'payment_method_is_active'      => (int) ($data['payment_method_is_active'] ?? false),
            'payment_method_sort_order'     => $data['payment_method_sort_order'] ?? 0,
        ];
    }

    private function saveIconFromFile(int $payment_method_id, string $file_path, int $admin_id): void
    {
        $method = $this->getMethodById($payment_method_id);
        $uploader = new ImageUploader();
        $paths = $uploader->saveSquareIcons($file_path, 'payment-methods/' . $payment_method_id, self::ICON_SIZES);
        $new_path = $paths['128'];
        $old_path = $method['payment_method_icon_path'];

        try {
            $this->db->execute(
                'UPDATE payment_methods SET payment_method_icon_path = :payment_method_icon_path WHERE payment_method_id = :payment_method_id',
                ['payment_method_icon_path' => $new_path, 'payment_method_id' => $payment_method_id]
            );
        } catch (Throwable $exception) {
            $uploader->deleteImageFiles(array_values($paths));
            throw $exception;
        }

        self::$cached_methods = null;
        if ($old_path !== null) {
            $uploader->deleteImageFiles([$old_path]);
        }
        (new AuditLog($this->db))->record('admin', $admin_id, 'payment_method.icon_changed', 'payment_method', $payment_method_id);
    }

    private function saveOptionalUploadedIcon(?array $uploaded_file, ImageUploader $uploader): ?string
    {
        if ($uploaded_file === null || ($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($uploaded_file['tmp_name'] ?? '')) {
            throw ApiException::validation(['payment_method_icon' => 'Choose a valid icon to upload.']);
        }

        return $uploader->saveSquareIcons($uploaded_file['tmp_name'], 'payment-methods', self::ICON_SIZES)['128'];
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
            'payment_method_icon_url'       => $method['payment_method_icon_path'] === null ? null : url($method['payment_method_icon_path']),
        ];
    }

}
