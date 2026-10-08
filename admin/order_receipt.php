<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// The order's receipt as a PDF, opened in the browser (from the "Print receipt" button on order_details.php)
$current_admin = AdminSession::requireLogin('orders.view');

$order_id = (int) ($_GET['id'] ?? 0);
$receipt  = adminLoadOrRedirect(fn () => (new Receipt(Database::instance()))->createReceiptForAdmin($order_id), 'orders.php');

adminSendFile($receipt['pdf'], 'application/pdf', $receipt['file_name'], inline: true);
