<?php

/**
 * CHIMBO bootstrap — the first file loaded by every entry point:
 * api/index.php, web storefront pages, admin pages and cron scripts.
 *
 * It loads the .env settings, registers the class autoloader,
 * and sets the timezone and error handling.
 */

define('BASE_PATH', __DIR__);

// 1. Autoloader: when a class is used for the first time, PHP calls this function,
//    which looks for "ClassName.php" in the src/ folders below. No require_once needed.
spl_autoload_register(function (string $class): void {
    static $folders = [
        'Core', 'Middleware', 'Controllers', 'Services', 'Repositories',
        'Payments', 'Payments/Dto', 'Payments/Gateways', 'Sms', 'Admin', 'Support',
    ];

    foreach ($folders as $folder) {
        $file = BASE_PATH . "/src/{$folder}/{$class}.php";
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

// 2. Composer libraries (PHPUnit, Dompdf …), once they are installed
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

// 3. Settings from .env
Env::load(BASE_PATH . '/.env');

// 4. Time and text: the app works in UTC (APP_TIMEZONE is only used when displaying dates)
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// 5. Errors: report everything, show details only when APP_DEBUG=true, always write to the log
error_reporting(E_ALL);
ini_set('display_errors', Env::get('APP_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-errors.log');

// Turn warnings and notices into exceptions so mistakes are never silently ignored
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // error was silenced with @
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
