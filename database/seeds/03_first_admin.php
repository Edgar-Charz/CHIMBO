<?php

/**
 * Creates the first super admin — only when there are no admins yet.
 * The email and name come from .env (SEED_ADMIN_EMAIL, SEED_ADMIN_NAME).
 * A random password is generated and printed ONCE; change it after the first login.
 */

return function (Database $db): void {
    // Skipped in automated tests, and once any admin exists
    $admin_count = (int) $db->fetchValue('SELECT COUNT(*) FROM admins');
    if ($admin_count > 0 || Env::get('APP_ENV') === 'testing') {
        return;
    }

    $admin_email = Env::required('SEED_ADMIN_EMAIL');
    $admin_name  = Env::get('SEED_ADMIN_NAME', 'CHIMBO Admin');
    $password    = bin2hex(random_bytes(8)); // 16 random characters

    $db->insert(
        'INSERT INTO admins (admin_full_name, admin_email, admin_password_hash, admin_role)
         VALUES (:admin_full_name, :admin_email, :admin_password_hash, :admin_role)',
        [
            'admin_full_name'     => $admin_name,
            'admin_email'         => $admin_email,
            'admin_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'admin_role'          => 'super_admin',
        ]
    );

    echo PHP_EOL . "First admin created: {$admin_email}" . PHP_EOL
        . "Password (shown only once — save it now): {$password}" . PHP_EOL . PHP_EOL;
};
