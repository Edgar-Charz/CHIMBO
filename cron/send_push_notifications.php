<?php

/** Delivers queued customer notifications through Firebase; schedule every minute. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$processed = (new NotificationPushQueue(Database::instance()))->process(new FcmPushSender());
echo gmdate('Y-m-d H:i:s') . " UTC — processed {$processed} push notification(s)" . PHP_EOL;
