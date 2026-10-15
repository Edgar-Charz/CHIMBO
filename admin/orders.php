<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// The rows are loaded by the table itself from ajax/orders.php; one order opens in order_details.php
$current_admin  = AdminSession::requireLogin('orders.view');
$order_manager  = new OrderManager(Database::instance());
$can_manage     = Admin::can($current_admin, 'orders.manage');

// "Expire overdue orders": unpaid orders past their time to pay → expired, stock back, customers told
// (the scheduled job cron/expire_unpaid_orders.php does the same on the server)
$form_error = adminHandleForm(function (string $form_action) use ($order_manager, $current_admin, $can_manage): void {
    if ($form_action !== 'expire_overdue' || !$can_manage) {
        throw ApiException::forbidden('Your role cannot do this.');
    }
    $expired_count = $order_manager->expireOverdueOrders((int) $current_admin['admin_id']);
    Session::flash('success', "{$expired_count} overdue order(s) marked expired; their stock is available again.");
    redirect(url('admin/orders.php'));
});
$overdue_count = $order_manager->countOverdueOrders();
$transport_count = $order_manager->countTransportToConfirm();
$to_pack_count = (new Dashboard(Database::instance()))->getOrderPipeline()['pipeline']['confirmed'];

// Filters can be opened from a link, e.g. the bell's "order(s) to pack" → ?order_status=confirmed, "overdue" → ?overdue=1,
// "transport price(s) to confirm" → ?transport_to_confirm=1
$chosen_status   = in_array($_GET['order_status'] ?? '', ADMIN_ORDER_STATUSES, true) ? $_GET['order_status'] : '';
$chosen_payment  = in_array($_GET['order_payment_status'] ?? '', ADMIN_PAYMENT_STATUSES, true) ? $_GET['order_payment_status'] : '';
$chosen_overdue  = ($_GET['overdue'] ?? '') === '1';
$chosen_transport = ($_GET['transport_to_confirm'] ?? '') === '1';

$page_title  = 'Orders';
$active_menu = 'orders';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions">
    <p class="text-muted mb-0">Orders from the app, and orders taken by staff by phone or in person.</p>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/orders.php?order_status=confirmed')) ?>">
            <i class="bi bi-box-seam"></i> To pack <span class="badge text-bg-<?= $to_pack_count > 0 ? 'warning' : 'secondary' ?>"><?= e($to_pack_count) ?></span>
        </a>
        <?php if ($can_manage && $overdue_count > 0): ?>
            <form method="post" data-confirm="<?= e("{$overdue_count} unpaid order(s) are past their time to pay. Mark them expired and release their stock? Customers are told.") ?>">
                <?= Csrf::field() ?>
                <button class="btn btn-outline-danger" type="submit" name="form_action" value="expire_overdue">
                    <i class="bi bi-hourglass-bottom"></i> Expire <?= e($overdue_count) ?> overdue order(s)
                </button>
            </form>
        <?php endif; ?>
        <?php if ($can_manage): ?>
            <a class="btn btn-chimbo" href="<?= e(url('admin/order_create.php')) ?>"><i class="bi bi-plus-lg"></i> Add order</a>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<form class="admin-panel filter-card" id="order-filters">
    <div class="filter-grid">
        <div>
            <label class="form-label" for="filter-order-status">Order status</label>
            <select class="form-select" id="filter-order-status" name="order_status">
                <option value="">All statuses</option>
                <?php foreach (ADMIN_ORDER_STATUSES as $status): ?>
                    <option value="<?= e($status) ?>" <?= $status === $chosen_status ? 'selected' : '' ?>><?= e(adminStatusName($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-payment-status">Payment</label>
            <select class="form-select" id="filter-payment-status" name="order_payment_status">
                <option value="">All payments</option>
                <?php foreach (ADMIN_PAYMENT_STATUSES as $status): ?>
                    <option value="<?= e($status) ?>" <?= $status === $chosen_payment ? 'selected' : '' ?>><?= e(adminStatusName($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-check align-self-end mb-2">
            <input class="form-check-input" type="checkbox" id="filter-overdue" name="overdue" value="1" <?= $chosen_overdue ? 'checked' : '' ?>>
            <label class="form-check-label" for="filter-overdue">
                Overdue unpaid <span class="badge text-bg-<?= $overdue_count > 0 ? 'danger' : 'secondary' ?>"><?= e($overdue_count) ?></span>
            </label>
        </div>
        <div class="form-check align-self-end mb-2">
            <input class="form-check-input" type="checkbox" id="filter-transport" name="transport_to_confirm" value="1" <?= $chosen_transport ? 'checked' : '' ?>>
            <label class="form-check-label" for="filter-transport">
                Transport to confirm <span class="badge text-bg-<?= $transport_count > 0 ? 'warning' : 'secondary' ?>"><?= e($transport_count) ?></span>
            </label>
        </div>
        <div class="filter-actions">
            <button class="btn btn-outline-secondary" type="reset"><i class="bi bi-x-lg"></i> Clear</button>
        </div>
    </div>
</form>

<div class="admin-panel admin-table-panel">
    <table class="table admin-table w-100" data-datatable-source="<?= e(url('admin/ajax/orders.php')) ?>"
           data-filter-form="#order-filters" data-title="All orders" data-icon="bi-receipt" data-order='[[7,"desc"]]'
           data-search-placeholder="Search order number, customer or phone" data-empty-message="No orders yet.">
        <thead>
            <tr>
                <th data-column="order" data-sort="order_number">Order</th>
                <th data-column="customer">Customer</th>
                <th data-column="region" data-cell-class="text-nowrap">Region</th>
                <th data-column="status" data-cell-class="text-nowrap">Status</th>
                <th data-column="payment" data-cell-class="text-nowrap">Payment</th>
                <th data-column="items" data-cell-class="text-end" class="text-end">Items</th>
                <th data-column="total" data-sort="order_total" data-cell-class="text-end text-nowrap" class="text-end">Total</th>
                <th data-column="placed" data-sort="order_placed_at" data-cell-class="text-nowrap">Placed</th>
                <th data-column="actions" data-cell-class="text-end" class="text-end">Actions</th>
            </tr>
        </thead>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
