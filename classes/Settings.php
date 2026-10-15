<?php

/**
 * Values from the `settings` table (support phone, OTP rules, limits, legal texts …) that staff can change.
 *
 * How to use it:
 *   $settings = new Settings(Database::instance());
 *   $settings->getInt('otp_lifetime_seconds', 300);
 *   $settings->getEditableSettings();                     // the admin "Settings" form, in groups
 *   $settings->updateSettings($_POST, $admin_id);         // saves the form (audit-logged)
 *   $settings->getSupportContacts();                      // "Msaada" in the apps (public)
 */
class Settings
{
    // Everything staff may edit: group title => [setting_key => [label, validation rule]].
    // The form shows them in this order.
    public const EDITABLE = [
        'Support contacts' => [
            'support_phone'    => ['Support phone', 'required|phone_tz'],
            'support_whatsapp' => ['Support WhatsApp', 'required|phone_tz'],
            'support_hours'    => ['Support hours (shown to customers)', 'required|string|max:100'],
        ],
        'Pickup point' => [
            'pickup_address' => ['Address where customers collect ("Nitachukua mwenyewe dukani")', 'required|string|max:200'],
            'pickup_hours'   => ['Opening hours for collecting', 'required|string|max:100'],
        ],
        'Orders' => [
            'cod_max_order_total'         => ['Largest cash-on-delivery order (TZS)', 'required|int|min:0|max:100000000'],
            'unpaid_order_expiry_minutes' => ['Time to pay before an unpaid order may be cancelled (minutes; 1440 = 24 hours). Applies to new app / website orders; orders made with "Add order" have no deadline', 'required|int|min:30|max:10080'],
        ],
        'Login codes (SMS)' => [
            'otp_lifetime_seconds'      => ['A code works for (seconds)', 'required|int|min:60|max:900'],
            'otp_max_attempts'          => ['Wrong guesses before a code is blocked', 'required|int|min:3|max:10'],
            'otp_resend_wait_seconds'   => ['Wait before "Tuma tena" (seconds)', 'required|int|min:30|max:300'],
            'otp_max_requests_per_hour' => ['Codes per phone number per hour', 'required|int|min:3|max:20'],
        ],
        'Legal texts (Kiswahili)' => [
            'legal_terms'   => ['Terms of use ("Vigezo na Masharti")', 'required|string|max:30000'],
            'legal_privacy' => ['Privacy policy ("Sera ya Faragha")', 'required|string|max:30000'],
        ],
    ];

    public const DEFAULT_UNPAID_ORDER_EXPIRY_MINUTES = 1440;   // 24 hours, the same as the seeded setting

    // Public pages built from settings: page name => [title, setting_key]
    private const LEGAL_PAGES = [
        'terms'   => ['Vigezo na Masharti', 'legal_terms'],
        'privacy' => ['Sera ya Faragha', 'legal_privacy'],
    ];

    // All settings, read once per request (the table is small)
    private static ?array $cached_settings = null;

    public function __construct(private Database $db)
    {
    }

    public function get(string $setting_key, ?string $default = null): ?string
    {
        if (self::$cached_settings === null) {
            $rows = $this->db->fetchAll('SELECT setting_key, setting_value FROM settings');
            self::$cached_settings = array_column($rows, 'setting_value', 'setting_key');
        }
        return self::$cached_settings[$setting_key] ?? $default;
    }

    public function getInt(string $setting_key, int $default): int
    {
        return (int) $this->get($setting_key, (string) $default);
    }

    /** Forgets the cached values (used by tests after changing a setting). */
    public static function clearCache(): void
    {
        self::$cached_settings = null;
    }

    /** The admin form: [group title => [[setting_key, label, setting_value], …]]. */
    public function getEditableSettings(): array
    {
        $groups = [];
        foreach (self::EDITABLE as $group_title => $fields) {
            foreach ($fields as $setting_key => [$label]) {
                $groups[$group_title][] = ['setting_key' => $setting_key, 'label' => $label, 'setting_value' => $this->get($setting_key, '')];
            }
        }
        return $groups;
    }

    /**
     * Saves the Settings form: every key of the given groups (EDITABLE titles; null = all groups) must be sent, so
     * one tab of the Settings page saves only its own fields. Only changed values are written; the audit log keeps
     * the old and new value of each. Throws a validation error (fields by setting_key).
     */
    public function updateSettings(array $input, int $admin_id, ?array $group_titles = null): void
    {
        $rules = [];
        foreach (self::EDITABLE as $group_title => $fields) {
            if ($group_titles !== null && !in_array($group_title, $group_titles, true)) {
                continue;
            }
            foreach ($fields as $setting_key => [, $rule]) {
                $rules[$setting_key] = $rule;
            }
        }
        $data = Validator::validate($input, $rules);

        $old_values = [];
        $new_values = [];
        foreach ($data as $setting_key => $value) {
            $value = trim((string) $value);
            if ($value !== $this->get($setting_key)) {
                $old_values[$setting_key] = $this->get($setting_key);
                $new_values[$setting_key] = $value;
            }
        }
        if ($new_values === []) {
            return;
        }

        $this->db->transaction(function (Database $db) use ($old_values, $new_values, $admin_id) {
            foreach ($new_values as $setting_key => $value) {
                $db->execute(
                    'INSERT INTO settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    ['setting_key' => $setting_key, 'setting_value' => $value]
                );
            }
            (new AuditLog($db))->record('admin', $admin_id, 'settings.updated', 'settings', null, $old_values, $new_values);
        });

        self::clearCache();
    }

    /** "Msaada" in the app and website (public). */
    public function getSupportContacts(): array
    {
        $whatsapp = (string) $this->get('support_whatsapp', '');

        return [
            'support_phone'        => $this->get('support_phone'),
            'support_whatsapp'     => $whatsapp,
            'support_whatsapp_url' => $whatsapp === '' ? null : 'https://wa.me/' . ltrim($whatsapp, '+'),
            'support_hours'        => $this->get('support_hours'),
        ];
    }

    /**
     * The time to pay an unpaid mobile money / bank order, in minutes (Settings → Orders & payments). Used whenever an
     * order gets a deadline: placed, upcountry transport confirmed, reactivated, or a payment rejected. Orders staff
     * create ("Add order") have no deadline on purpose: those customers may pay in person.
     */
    public function unpaidOrderExpiryMinutes(): int
    {
        return $this->getInt('unpaid_order_expiry_minutes', self::DEFAULT_UNPAID_ORDER_EXPIRY_MINUTES);
    }

    /** Where and when customers collect a pickup order (R-26): {pickup_address, pickup_hours}. */
    public function getPickupPoint(): array
    {
        return [
            'pickup_address' => $this->get('pickup_address'),
            'pickup_hours'   => $this->get('pickup_hours'),
        ];
    }

    /** A legal page ("terms" or "privacy") for the apps and website: {page_title, page_body, updated_at}. 404 for others. */
    public function getLegalPage(string $page_name): array
    {
        if (!isset(self::LEGAL_PAGES[$page_name])) {
            throw ApiException::notFound('Ukurasa haukupatikana.');
        }
        [$page_title, $setting_key] = self::LEGAL_PAGES[$page_name];

        $row = $this->db->fetchOne(
            'SELECT setting_value, updated_at FROM settings WHERE setting_key = :setting_key',
            ['setting_key' => $setting_key]
        );

        return [
            'page_title' => $page_title,
            'page_body'  => $row['setting_value'] ?? '',   // plain text; paragraphs separated by empty lines
            'updated_at' => isoDate($row['updated_at'] ?? null),
        ];
    }
}
