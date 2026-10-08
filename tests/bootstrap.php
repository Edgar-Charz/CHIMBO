<?php

/**
 * Test setup (run by PHPUnit before any test).
 * Tests use their own database — chimbo_test, set in phpunit.xml — rebuilt from the migrations
 * and seeds on every run, so the real `chimbo` database is never touched.
 */

require dirname(__DIR__) . '/bootstrap.php';

$test_database = Env::required('DB_DATABASE');
if ($test_database === 'chimbo') {
    throw new RuntimeException('Tests must never run on the real database. Check DB_DATABASE in phpunit.xml.');
}

// Connect to the server without choosing a database, then recreate the test database
$server = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '3306')),
    Env::get('DB_USERNAME', 'root'),
    (string) Env::get('DB_PASSWORD', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec("DROP DATABASE IF EXISTS `{$test_database}`");
$server->exec("CREATE DATABASE `{$test_database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$migrator = new Migrator(Database::instance());
$migrator->runPendingMigrations();
$migrator->runSeeds();

/**
 * Empties every table that holds customer data (children before parents, because of the foreign keys).
 * Called by tests that need a clean start. The catalog, regions and settings are left alone.
 */
function resetCustomerData(Database $db): void
{
    $tables = [
        'notifications', 'payments', 'order_deliveries', 'order_status_history', 'order_items', 'orders',
        'wishlist_items', 'cart_items', 'addresses', 'auth_tokens', 'business_profiles',
        'otp_codes', 'rate_limits', 'sms_outbox', 'users',
    ];
    foreach ($tables as $table) {
        $db->execute("DELETE FROM {$table}");
    }
}
