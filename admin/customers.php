<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// The rows are loaded by the table itself from ajax/customers.php
$current_admin = AdminSession::requireLogin('customers.view');
$regions       = (new Customer(Database::instance()))->getRegionsWithCustomers();

$page_title  = 'Customers';
$active_menu = 'customers';
require __DIR__ . '/includes/header.php';
?>

<form class="admin-panel filter-card" id="customer-filters">
    <div class="filter-grid">
        <div>
            <label class="form-label" for="filter-user-status">Status</label>
            <select class="form-select" id="filter-user-status" name="user_status">
                <option value="">All statuses</option>
                <?php foreach (Customer::STATUSES as $status): ?>
                    <option value="<?= e($status) ?>"><?= e(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-verification">Business verification</label>
            <select class="form-select" id="filter-verification" name="business_verification_status">
                <option value="">Any verification</option>
                <?php foreach (Customer::VERIFICATION_STATUSES as $status): ?>
                    <option value="<?= e($status) ?>"><?= e(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-region">Region</label>
            <select class="form-select" id="filter-region" name="region_id">
                <option value="">All regions</option>
                <?php foreach ($regions as $region): ?>
                    <option value="<?= e($region['region_id']) ?>"><?= e($region['region_name']) ?> (<?= e($region['customer_count']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn btn-outline-secondary" type="reset"><i class="bi bi-x-lg"></i> Clear</button>
        </div>
    </div>
</form>

<div class="admin-panel admin-table-panel">
    <table class="table admin-table w-100" data-datatable-source="<?= e(url('admin/ajax/customers.php')) ?>"
           data-filter-form="#customer-filters" data-title="All customers" data-icon="bi-people"
           data-order='[[5,"desc"]]' data-search-placeholder="Search name, phone or shop"
           data-empty-message="No customers yet. They appear here after registering in the app.">
        <thead>
            <tr>
                <th data-column="customer" data-sort="user_full_name">Customer</th>
                <th data-column="shop" data-sort="business_name">Shop</th>
                <th data-column="region" data-sort="region_name">Region</th>
                <th data-column="verification" data-sort="business_verification_status">Verification</th>
                <th data-column="status" data-sort="user_status">Status</th>
                <th data-column="joined" data-sort="created_at" data-cell-class="text-nowrap">Joined</th>
                <th data-column="last_login" data-sort="user_last_login_at" data-cell-class="text-nowrap">Last login</th>
                <th data-column="actions" data-cell-class="text-end" class="text-end">Actions</th>
            </tr>
        </thead>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
