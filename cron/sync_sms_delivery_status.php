<?php

/** Checks DARSMS delivery receipts; schedule every five minutes. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

if (Env::get('SMS_DRIVER', 'log') !== 'darsms') {
    echo gmdate('Y-m-d H:i:s') . " UTC — SMS_DRIVER is not darsms; skipped" . PHP_EOL;
    exit;
}

$checked = (new SmsDeliveryStatusSync(Database::instance()))->sync();
echo gmdate('Y-m-d H:i:s') . " UTC — checked {$checked} SMS delivery status(es)" . PHP_EOL;
