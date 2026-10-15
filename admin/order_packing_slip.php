<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// The order's packing slip as a PDF (what to pack, where it goes, QR with the order number),
// opened in the browser from the "Packing slip" button on order_details.php or the Orders list
$current_admin = AdminSession::requireLogin('orders.view');

$order_id = (int) ($_GET['id'] ?? 0);
$slip     = adminLoadOrRedirect(fn () => (new Receipt(Database::instance()))->createPackingSlip($order_id), 'orders.php');

adminSendFile($slip['pdf'], 'application/pdf', $slip['file_name'], inline: true);
