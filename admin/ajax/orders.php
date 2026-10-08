<?php

/** Rows for the Orders table (server-side DataTables, see admin/orders.php). */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

AdminSession::requireAjaxLogin('orders.view');

try {
    $pagination = (new OrderManager(Database::instance()))->getOrdersForAdmin(adminDataTablesInput($_GET, 'q'));
} catch (ApiException $e) {
    adminDataTablesError($e);
}

// Orders placed in the app say "App"; the others were taken on the web or by staff
$channel_names = ['app' => 'App', 'web' => 'Web / staff'];

adminDataTablesJson($pagination, fn (array $order): array => [
    'order' => '<a class="stretched-link fw-semibold" href="' . e(url('admin/order_details.php?id=' . $order['order_id'])) . '">'
        . e($order['order_number']) . '</a>'
        . '<div class="small text-muted">' . e($channel_names[$order['order_channel']] ?? $order['order_channel']) . '</div>',
    'customer' => '<div class="fw-semibold">' . e($order['user_full_name'] ?? 'No name yet') . '</div>'
        . '<div class="small text-muted">' . e(Phone::format($order['user_phone'])) . '</div>',
    'region'   => e($order['order_ship_region_name']),
    'status'   => adminStatusBadge($order['order_status']),
    'payment'  => adminStatusBadge($order['order_payment_status'])
        . '<div class="small text-muted">' . e(adminPaymentMethodName($order['order_payment_method'])) . '</div>',
    'items'    => e(number_format((int) $order['item_count'])),
    'total'    => e(adminMoney((int) $order['order_total'])),
    'placed'   => e(adminDateTime($order['order_placed_at'])),
    'actions'  => adminRowActions(
        adminActionLink('bi-eye', 'View order', url('admin/order_details.php?id=' . $order['order_id'])),
        adminActionLink('bi-printer', 'Print receipt', url('admin/order_receipt.php?id=' . $order['order_id']), new_tab: true),
    ),
]);
