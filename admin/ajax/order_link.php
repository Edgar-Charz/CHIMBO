<?php

/** Resolves a signed QR code to an admin order page only for staff with order access. */
require dirname(__DIR__) . '/includes/admin_bootstrap.php';

AdminSession::requireAjaxLogin('orders.view', false);
$code = (string) ($_GET['code'] ?? '');
$order = (new OrderLink(Database::instance()))->findOrderForCode($code);

header('Cache-Control: private, no-store');
if ($order === null) {
    http_response_code(404);
    adminSendJson(['error' => 'Order not found.']);
}

adminSendJson(['url' => url('admin/order_details.php?id=' . (int) $order['order_id'])]);
