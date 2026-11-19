<?php

require __DIR__ . '/includes/admin_bootstrap.php';
AdminSession::requireLogin('products.manage');

$image_path = Database::instance()->fetchValue(
    'SELECT product_request_image_path FROM product_requests WHERE product_request_id = :id',
    ['id' => (int) ($_GET['id'] ?? 0)]
);
$full_path = is_string($image_path) ? realpath(BASE_PATH . '/' . $image_path) : false;
$private_directory = realpath(BASE_PATH . '/media/product-requests');
if ($full_path === false || $private_directory === false || !str_starts_with($full_path, $private_directory . DIRECTORY_SEPARATOR) || !is_file($full_path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/webp');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
readfile($full_path);
