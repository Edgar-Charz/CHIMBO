<?php

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');
$config = require __DIR__ . '/firebase_web_config.php';
unset($config['vapidKey']);
echo 'firebase.initializeApp(' . json_encode($config, JSON_UNESCAPED_SLASHES) . ');';