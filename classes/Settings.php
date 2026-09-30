<?php

/**
 * Values from the `settings` table (support phone, OTP rules, limits …) that staff can change.
 *
 * How to use it:
 *   $settings = new Settings(Database::instance());
 *   $settings->getInt('otp_lifetime_seconds', 300);
 */
class Settings
{
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
}
