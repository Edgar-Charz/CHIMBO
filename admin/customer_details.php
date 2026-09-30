<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('customers.view');

$user_id  = (int) ($_GET['id'] ?? 0);
$customer = (new Customer(Database::instance()))->getCustomerById($user_id);

if ($customer === null) {
    Session::flash('error', 'That customer was not found.');
    redirect(url('admin/customers.php'));
}

$customer_name = $customer['user_full_name'] ?? 'Registration not finished';
$language_names = ['sw' => 'Kiswahili', 'en' => 'English'];

$page_title  = 'Customer';
$active_menu = 'customers';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/customers.php')) ?>"><i class="bi bi-arrow-left"></i> Customers</a>

<div class="admin-panel mb-3">
    <div class="admin-panel-body d-flex flex-wrap align-items-center gap-3">
        <span class="initials-avatar avatar-large"><?= e(adminInitials($customer['user_full_name'])) ?></span>
        <div class="flex-grow-1">
            <h2 class="h4 mb-1"><?= e($customer_name) ?></h2>
            <div class="text-muted">
                <?= e(Phone::format($customer['user_phone'])) ?>
                <?php if ($customer['business_name']): ?> · <?= e($customer['business_name']) ?><?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <?= adminStatusBadge($customer['user_status']) ?>
            <?php if ($customer['business_verification_status']): ?>
                <?= adminStatusBadge($customer['business_verification_status']) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Account</h3></div>
            <div class="admin-panel-body">
                <dl class="admin-details">
                    <dt>Customer ID</dt>        <dd>#<?= e($customer['user_id']) ?></dd>
                    <dt>Phone</dt>              <dd><?= e(Phone::format($customer['user_phone'])) ?></dd>
                    <dt>Phone verified</dt>     <dd><?= e(adminDateTime($customer['user_phone_verified_at'])) ?></dd>
                    <dt>Email</dt>              <dd><?= e($customer['user_email'] ?? '—') ?></dd>
                    <dt>App language</dt>       <dd><?= e($language_names[$customer['user_locale']] ?? $customer['user_locale']) ?></dd>
                    <dt>Joined</dt>             <dd><?= e(adminDateTime($customer['created_at'])) ?></dd>
                    <dt>Last login</dt>         <dd><?= e(adminDateTime($customer['user_last_login_at'])) ?></dd>
                    <dt>Signed in on</dt>       <dd><?= e($customer['active_app_sessions']) ?> device(s)</dd>
                    <?php if ($customer['deleted_at']): ?>
                        <dt>Deleted</dt>        <dd><?= e(adminDateTime($customer['deleted_at'])) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Business</h3></div>
            <div class="admin-panel-body">
                <?php if ($customer['business_verification_status'] === null): ?>
                    <p class="text-muted mb-0">The customer has not filled in their business details yet (registration step 3).</p>
                <?php else: ?>
                    <dl class="admin-details">
                        <dt>Shop name</dt>      <dd><?= e($customer['business_name'] ?? '—') ?></dd>
                        <dt>Region</dt>         <dd><?= e($customer['region_name'] ?? '—') ?></dd>
                        <dt>District</dt>       <dd><?= e($customer['district_name'] ?? '—') ?></dd>
                        <dt>Verification</dt>   <dd><?= adminStatusBadge($customer['business_verification_status']) ?></dd>
                        <?php if ($customer['business_verified_at']): ?>
                            <dt>Verified</dt>
                            <dd><?= e(adminDateTime($customer['business_verified_at'])) ?> by <?= e($customer['verified_by_admin_name'] ?? '—') ?></dd>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="admin-panel">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Orders</h3></div>
            <div class="admin-empty-state">
                <i class="bi bi-receipt"></i>
                <p class="mt-2 mb-0">This customer's orders will appear here once ordering is built (Phase 4).</p>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
