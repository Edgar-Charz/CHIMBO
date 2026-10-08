<?php

/** Rows for the Customers table (server-side DataTables, see admin/customers.php). */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

AdminSession::requireAjaxLogin('customers.view');

try {
    $pagination = (new Customer(Database::instance()))->searchCustomers(adminDataTablesInput($_GET, 'search'));
} catch (ApiException $e) {
    adminDataTablesError($e);
}

adminDataTablesJson($pagination, fn(array $customer): array => [
    'customer' => '<div class="d-flex align-items-center gap-2">'
        . '<span class="initials-avatar">' . e(adminInitials($customer['user_full_name'])) . '</span>'
        . '<div><a class="stretched-link fw-semibold" href="' . e(url('admin/customer_details.php?id=' . $customer['user_id'])) . '">'
        . e($customer['user_full_name'] ?? 'Registration not finished') . '</a>'
        . '<div class="small text-muted">' . e(Phone::format($customer['user_phone'])) . '</div></div></div>',
    'shop'         => e($customer['business_name'] ?? '—'),
    'region'       => e($customer['region_name'] ?? '—'),
    'verification' => $customer['business_verification_status'] ? adminStatusBadge($customer['business_verification_status']) : '—',
    'status'       => adminStatusBadge($customer['user_status']),
    'joined'       => e(adminDate($customer['created_at'])),
    'last_login'   => e(adminDateTime($customer['user_last_login_at'])),
    'actions'      => adminRowActions(
        adminActionLink('bi-eye', 'View customer', url('admin/customer_details.php?id=' . $customer['user_id'])),
    ),
]);
