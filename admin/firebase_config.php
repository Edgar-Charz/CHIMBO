<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(require __DIR__ . '/firebase_web_config.php', JSON_UNESCAPED_SLASHES);