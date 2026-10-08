<?php

/**
 * Rows for the payments tables on admin/payments.php (server-side DataTables).
 * ?tab=review|all says which tab the buttons send their forms back to.
 */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

AdminSession::requireAjaxLogin('payments.manage');

$tab       = in_array($_GET['tab'] ?? '', ['review', 'all'], true) ? $_GET['tab'] : 'all';
$list_page = url("admin/payments.php?tab={$tab}");

try {
    $pagination = (new Payment(Database::instance()))->searchPayments(adminDataTablesInput($_GET, 'search'));
} catch (ApiException $e) {
    adminDataTablesError($e);
}

adminDataTablesJson($pagination, function (array $payment) use ($list_page): array {
    $order_link     = url('admin/order_details.php?id=' . $payment['order_id']);
    $amount_differs = (int) $payment['payment_amount'] !== (int) $payment['order_total'];
    $is_waiting     = $payment['payment_status'] === 'submitted';

    return [
        'order'    => '<a class="fw-semibold" href="' . e($order_link) . '">' . e($payment['order_number']) . '</a>'
            . ($payment['submitted_by_admin_name'] ? '<div class="small text-muted">Recorded by ' . e($payment['submitted_by_admin_name']) . '</div>' : ''),
        'customer' => '<div>' . e($payment['user_full_name'] ?? 'No name yet') . '</div>'
            . '<div class="small text-muted">' . e(Phone::format($payment['user_phone'])) . ($payment['business_name'] ? ' · ' . e($payment['business_name']) : '') . '</div>',
        'method'   => e(adminPaymentMethodName($payment['payment_method_code'])),
        'amount'   => '<span class="fw-semibold">' . e(adminMoney((int) $payment['payment_amount'])) . '</span>'
            . ($amount_differs ? '<div class="small text-danger">Order total ' . e(adminMoney((int) $payment['order_total'])) . '</div>' : ''),
        'payer'    => e(adminPayerAccount($payment['payment_payer_account'])),
        'code'     => '<code class="payment-code">' . e($payment['payment_reference']) . '</code>',
        'sent'     => e(adminDateTime($payment['created_at'])),
        'status'   => adminPaymentReviewBadge($payment['payment_status'])
            . ($is_waiting
                ? '<div class="small">' . adminTimeLeft($payment['order_expires_at']) . '</div>'
                : '<div class="small text-muted">' . e(trim(($payment['reviewed_by_admin_name'] ?? '') . ' · ' . adminDateTime($payment['payment_reviewed_at']), ' ·')) . '</div>')
            . ($payment['payment_review_note'] ? '<div class="small text-muted">' . e($payment['payment_review_note']) . '</div>' : ''),
        'actions'  => adminRowActions(
            $is_waiting ? adminPaymentReviewButtons($payment, $payment['order_number'], $list_page) : '',
            adminActionLink('bi-eye', 'View order', $order_link),
        ),
    ];
}, '');   // plain rows: they hold several links and buttons
