<?php

/**
 * Default settings. Staff can change them later in the admin dashboard.
 * Safe to run again: INSERT IGNORE never overwrites a value that already exists.
 */

$default_settings = [
    // Support contacts shown in "Msaada" and used for "Uliza Muuzaji" (fill in the real numbers)
    'support_phone'               => '+255700000000',
    'support_whatsapp'            => '+255700000000',
    'support_hours'               => 'Jumatatu - Jumamosi, 08:00 - 18:00',

    // One-time login codes
    'otp_lifetime_seconds'        => '300',   // a code works for 5 minutes
    'otp_max_attempts'            => '5',     // wrong guesses before the code is blocked
    'otp_resend_wait_seconds'     => '60',    // wait before "Tuma tena"
    'otp_max_requests_per_hour'   => '5',     // per phone number

    // Orders and payments
    'unpaid_order_expiry_minutes' => '30',    // unpaid mobile-money orders are cancelled after this
    'cod_max_order_total'         => '300000', // largest order allowed with "Lipa ukipokea" (TZS)

    // Payment methods shown at checkout (comma-separated): mpesa, airtel_money, mixx, bank, cod.
    // Only cash on delivery until mobile money is connected (Phase 5).
    'enabled_payment_methods'     => 'cod',
];

return function (Database $db) use ($default_settings): void {
    foreach ($default_settings as $setting_key => $setting_value) {
        $db->execute(
            'INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)',
            ['setting_key' => $setting_key, 'setting_value' => $setting_value]
        );
    }
};
