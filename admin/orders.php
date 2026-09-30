<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// The rows are loaded by the table itself from ajax/orders.php; one order opens in order_details.php
$current_admin = AdminSession::requireLogin('orders.view');

$page_title  = 'Orders';
$active_menu = 'orders';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions">
    <p class="text-muted mb-0">Orders from the app, and orders taken by staff by phone or in person.</p>
    <?php if (Admin::can($current_admin, 'orders.manage')): ?>
        <a class="btn btn-chimbo" href="<?= e(url('admin/order_create.php')) ?>"><i class="bi bi-plus-lg"></i> Add order</a>
    <?php endif; ?>
</div>

<form class="admin-panel filter-card" id="order-filters">
    <div class="filter-grid">
        <div>
            <label class="form-label" for="filter-order-status">Order status</label>
            <select class="form-select" id="filter-order-status" name="order_status">
                <option value="">All statuses</option>
                <?php foreach (ADMIN_ORDER_STATUSES as $status): ?>
                    <option value="<?= e($status) ?>"><?= e(adminStatusName($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-payment-status">Payment</label>
            <select class="form-select" id="filter-payment-status" name="order_payment_status">
                <option value="">All payments</option>
                <?php foreach (ADMIN_PAYMENT_STATUSES as $status): ?>
                    <option value="<?= e($status) ?>"><?= e(adminStatusName($status)) ?></option>
                <?php endforeach; ?>
            </select>
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
            </tr>
        </thead>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
