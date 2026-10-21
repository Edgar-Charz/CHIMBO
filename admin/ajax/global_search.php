<?php

/** A few permission-checked order, customer and product matches for the admin top-bar search. */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::currentAdmin();
if ($current_admin === null) {
    http_response_code(401);
    adminSendJson(['error' => 'Your session has ended. Please log in again.']);
}

header('Cache-Control: private, no-store');

$search_permissions = ['orders.view', 'customers.view', 'products.manage'];
$can_search = array_filter($search_permissions, fn (string $permission): bool => Admin::can($current_admin, $permission));
if ($can_search === []) {
    http_response_code(403);
    adminSendJson(['error' => 'Your role does not have access to search records.']);
}

try {
    $search = Validator::validate(
        ['q' => $_GET['q'] ?? null],
        ['q' => 'required|string|min:2|max:100']
    )['q'];
} catch (ApiException $e) {
    http_response_code(422);
    adminSendJson(['error' => $e->getMessage()]);
}

$database = Database::instance();
$groups = [];

if (Admin::can($current_admin, 'orders.view')) {
    $orders = (new OrderManager($database))->getOrdersForAdmin(['q' => $search, 'page' => 1, 'per_page' => 10]);
    $items = array_map(static fn (array $order): array => [
        'title' => $order['order_number'],
        'detail' => trim(implode(' · ', array_filter([
            $order['user_full_name'] ?: 'No name yet',
            adminStatusName($order['order_status'], (bool) $order['order_is_pickup']),
            adminMoney((int) $order['order_total']),
        ]))),
        'url' => url('admin/order_details.php?id=' . (int) $order['order_id']),
    ], array_slice($orders['items'], 0, 5));
    if ($items !== []) {
        $groups[] = ['label' => 'Orders', 'icon' => 'bi-receipt', 'items' => $items];
    }
}

if (Admin::can($current_admin, 'customers.view')) {
    $customers = (new Customer($database))->searchCustomers(['search' => $search, 'page' => 1, 'per_page' => 10]);
    $items = array_map(static fn (array $customer): array => [
        'title' => $customer['user_full_name'] ?: 'Registration not finished',
        'detail' => trim(implode(' · ', array_filter([
            Phone::format($customer['user_phone']),
            $customer['business_name'] ?? null,
        ]))),
        'url' => url('admin/customer_details.php?id=' . (int) $customer['user_id']),
    ], array_slice($customers['items'], 0, 5));
    if ($items !== []) {
        $groups[] = ['label' => 'Customers', 'icon' => 'bi-people', 'items' => $items];
    }
}

if (Admin::can($current_admin, 'products.manage')) {
    $products = (new ProductEditor($database))->getProductsForAdmin(['q' => $search, 'page' => 1, 'per_page' => 10]);
    $items = array_map(static fn (array $product): array => [
        'title' => $product['product_name'],
        'detail' => trim(implode(' · ', array_filter([
            $product['product_sku'],
            $product['product_price'] === null ? 'No price set' : adminMoney((int) $product['product_price']),
            $product['product_is_active'] ? 'Active' : 'Hidden',
        ]))),
        'url' => url('admin/product_edit.php?id=' . (int) $product['product_id']),
    ], array_slice($products['items'], 0, 5));
    if ($items !== []) {
        $groups[] = ['label' => 'Products', 'icon' => 'bi-box-seam', 'items' => $items];
    }
}

$result_count = array_sum(array_map(static fn (array $group): int => count($group['items']), $groups));
adminSendJson(['groups' => $groups, 'result_count' => $result_count]);
