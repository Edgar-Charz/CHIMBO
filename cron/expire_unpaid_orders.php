<?php

/**
 * Scheduled job: marks unpaid mobile money / bank orders past their time to pay as "expired"
 * (stock back, customer told). The same work as the admin "Expire overdue orders" button.
 *
 * On the server, run it every 15 minutes, e.g. with cron:
 *   0,15,30,45 * * * *  php /path/to/chimbo/cron/expire_unpaid_orders.php
 * Locally:
 *   C:\xampp\php\php.exe cron\expire_unpaid_orders.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);   // never runs from a browser (the folder is also blocked by .htaccess)
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$expired_count = (new OrderManager(Database::instance()))->expireOverdueOrders(null);
echo gmdate('Y-m-d H:i:s') . " UTC — expired {$expired_count} unpaid order(s)" . PHP_EOL;
