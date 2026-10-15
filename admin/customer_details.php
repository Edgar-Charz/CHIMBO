<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('customers.view');

$user_id  = (int) ($_GET['id'] ?? 0);
$customer = (new Customer(Database::instance()))->getCustomerById($user_id);

if ($customer === null) {
    Session::flash('error', 'That customer was not found.');
    redirect(url('admin/customers.php'));
}

$can_manage_customer = Admin::can($current_admin, 'customers.manage');

// Staff actions (permission customers.manage). The classes write the audit log and notify the customer.
//   force_pin_reset            "Lazimisha kubadili PIN": locks the PIN and logs the customer out on every device
//   set_business_verification  Verify / reject (with a reason) / reset the shop's verification
//   set_customer_status        Suspend (with a reason; logs them out everywhere) / reactivate
$form_error = adminHandleForm(function (string $form_action) use ($user_id, $current_admin, $can_manage_customer): void {
    if (!$can_manage_customer) {
        throw ApiException::forbidden('Your role cannot do this.');
    }
    $admin_id = (int) $current_admin['admin_id'];

    if ($form_action === 'force_pin_reset') {
        try {
            (new CustomerPin(Database::instance()))->forcePinReset($user_id, $admin_id);
            Session::flash('success', 'Mteja atalazimika kuweka PIN mpya atakapoingia tena.');
        } catch (ApiException $e) {
            if ($e->errorCode() !== 'NOT_FOUND') {
                throw $e;
            }
            Session::flash('error', $e->getMessage());   // "This customer has no PIN to reset."
        }
    } elseif ($form_action === 'set_business_verification') {
        (new Customer(Database::instance()))->setBusinessVerification($user_id, $_POST, $admin_id);
        Session::flash('success', 'Business verification updated.');
    } elseif ($form_action === 'set_customer_status') {
        (new Customer(Database::instance()))->setCustomerStatus($user_id, $_POST, $admin_id);
        Session::flash('success', ($_POST['user_status'] ?? '') === 'suspended'
            ? 'Account suspended. The customer was logged out everywhere.'
            : 'Account active again. The customer can log in.');
    } else {
        throw ApiException::forbidden('Your role cannot do this.');
    }
    redirect(url("admin/customer_details.php?id={$user_id}"));
});
$errors = $form_error?->fields() ?? [];

$pin_status = Database::instance()->fetchOne(
    'SELECT user_pin_hash IS NOT NULL AS user_has_pin,
            user_pin_locked_at IS NOT NULL AS user_pin_is_locked
     FROM users
     WHERE user_id = :user_id',
    ['user_id' => $user_id]
);

// The customer's orders, newest first, a page at a time (the same list the customer sees in the app's Oda tab)
$orders_page     = max(1, (int) ($_GET['orders_page'] ?? 1));
$customer_orders = (new Order(Database::instance()))->getOrders($user_id, ['page' => $orders_page]);
$orders_pages    = max(1, (int) ceil($customer_orders['total'] / $customer_orders['per_page']));
$can_view_orders = Admin::can($current_admin, 'orders.view');

$customer_name = $customer['user_full_name'] ?? 'Registration not finished';
$language_names = ['sw' => 'Kiswahili', 'en' => 'English'];

$page_title  = 'Customer';
$active_menu = 'customers';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/customers.php')) ?>"><i class="bi bi-arrow-left"></i> Customers</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

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
                <?= adminVerificationBadge($customer['business_verification_status']) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($can_manage_customer && $customer['user_status'] !== 'deleted'): ?>
    <div class="admin-panel mb-3">
        <div class="admin-panel-body">
            <?php if ($customer['user_status'] === 'suspended'): ?>
                <form class="d-flex flex-wrap align-items-center gap-3" method="post"
                      data-confirm="Reactivate this account? The customer will be able to log in again.">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="user_status" value="active">
                    <span class="text-danger"><i class="bi bi-slash-circle"></i> This account is suspended — the customer cannot log in.</span>
                    <button class="btn btn-success ms-auto" type="submit" name="form_action" value="set_customer_status">
                        <i class="bi bi-play-circle"></i> Reactivate account
                    </button>
                </form>
            <?php else: ?>
                <form method="post" data-confirm="Suspend this account? The customer will be logged out everywhere and cannot log in until reactivated. Their orders are not changed.">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="user_status" value="suspended">
                    <label class="form-label small" for="user_status_reason">Suspend — reason (staff only, kept in the audit log)</label>
                    <div class="input-group">
                        <input class="form-control<?= adminInvalidClass($errors, 'user_status_reason') ?>" id="user_status_reason"
                               name="user_status_reason" maxlength="255" placeholder="e.g. Fake orders, refused deliveries">
                        <button class="btn btn-outline-danger" type="submit" name="form_action" value="set_customer_status">
                            <i class="bi bi-slash-circle"></i> Suspend account
                        </button>
                    </div>
                    <?= adminFieldError($errors, 'user_status_reason') ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="admin-panel h-100">
            <div class="admin-panel-header"><h3 class="admin-panel-title">Account</h3></div>
            <div class="admin-panel-body">
                <dl class="admin-details">
                    <dt>Customer ID</dt>        <dd>#<?= e($customer['user_id']) ?></dd>
                    <dt>Phone</dt>              <dd><?= e(Phone::format($customer['user_phone'])) ?></dd>
                    <dt>Phone verified</dt>     <dd><?= e(adminDateTime($customer['user_phone_verified_at'])) ?></dd>
                    <dt>Ana PIN</dt>            <dd><?= $pin_status['user_has_pin'] ? 'Ndiyo' : 'Hapana' ?></dd>
                    <dt>PIN imefungwa</dt>      <dd><?= $pin_status['user_pin_is_locked'] ? 'Ndiyo' : 'Hapana' ?></dd>
                    <dt>Email</dt>              <dd><?= e($customer['user_email'] ?? '—') ?></dd>
                    <dt>App language</dt>       <dd><?= e($language_names[$customer['user_locale']] ?? $customer['user_locale']) ?></dd>
                    <dt>Joined</dt>             <dd><?= e(adminDateTime($customer['created_at'])) ?></dd>
                    <dt>Last login</dt>         <dd><?= e(adminDateTime($customer['user_last_login_at'])) ?></dd>
                    <dt>Signed in on</dt>       <dd><?= e($customer['active_app_sessions']) ?> device(s)</dd>
                    <?php if ($customer['deleted_at']): ?>
                        <dt>Deleted</dt>        <dd><?= e(adminDateTime($customer['deleted_at'])) ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if ($pin_status['user_has_pin'] && $can_manage_customer): ?>
                    <form class="mt-3" method="post" data-confirm="Lazimisha mteja kuweka PIN mpya? Atatolewa kwenye vifaa vyake vyote.">
                        <?= Csrf::field() ?>
                        <button class="btn btn-outline-danger" type="submit" name="form_action" value="force_pin_reset">
                            <i class="bi bi-shield-lock"></i> Lazimisha kubadili PIN
                        </button>
                    </form>
                <?php endif; ?>
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
                        <dt>Verification</dt>   <dd><?= adminVerificationBadge($customer['business_verification_status']) ?></dd>
                        <?php if ($customer['business_verified_at']): ?>
                            <dt>Verified</dt>
                            <dd><?= e(adminDateTime($customer['business_verified_at'])) ?> by <?= e($customer['verified_by_admin_name'] ?? '—') ?></dd>
                        <?php endif; ?>
                    </dl>

                    <?php if ($can_manage_customer): ?>
                        <?php $verification_status = $customer['business_verification_status']; ?>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <?php if ($verification_status !== 'verified'): ?>
                                <form method="post" data-confirm="Thibitisha biashara hii? Mteja ataona alama ya 'Biashara Imethibitishwa'.">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="business_verification_status" value="verified">
                                    <button class="btn btn-success" type="submit" name="form_action" value="set_business_verification">
                                        <i class="bi bi-patch-check"></i> Verify business
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($verification_status !== 'unverified'): ?>
                                <form method="post" data-confirm="Remove the verification decision and set this business back to not verified?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="business_verification_status" value="unverified">
                                    <button class="btn btn-outline-secondary" type="submit" name="form_action" value="set_business_verification">
                                        <i class="bi bi-arrow-counterclockwise"></i> Set back to not verified
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php if ($verification_status !== 'rejected'): ?>
                            <form class="mt-3" method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="business_verification_status" value="rejected">
                                <label class="form-label small" for="business_verification_note">Reject — reason for the customer (Kiswahili)</label>
                                <div class="input-group">
                                    <input class="form-control<?= adminInvalidClass($errors, 'business_verification_note') ?>" id="business_verification_note"
                                           name="business_verification_note" maxlength="255" placeholder="mfano: Jina la duka halilingani na leseni.">
                                    <button class="btn btn-outline-danger" type="submit" name="form_action" value="set_business_verification">
                                        <i class="bi bi-x-circle"></i> Reject
                                    </button>
                                </div>
                                <?= adminFieldError($errors, 'business_verification_note') ?>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h3 class="admin-panel-title">Orders</h3>
                <span class="small text-muted"><?= e(number_format($customer_orders['total'])) ?> order(s)</span>
            </div>
            <?php if (!$customer_orders['items']): ?>
                <div class="admin-empty-state">
                    <i class="bi bi-receipt"></i>
                    <p class="mt-2 mb-0">This customer has not ordered yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Placed</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th class="text-end">Items</th>
                                <th class="text-end">Total</th>
                                <?php if ($can_view_orders): ?>
                                    <th class="text-end">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customer_orders['items'] as $order): ?>
                                <tr class="<?= $can_view_orders ? 'row-link' : '' ?>">
                                    <td>
                                        <?php if ($can_view_orders): ?>
                                            <a class="stretched-link fw-semibold" href="<?= e(url('admin/order_details.php?id=' . $order['order_id'])) ?>"><?= e($order['order_number']) ?></a>
                                        <?php else: ?>
                                            <span class="fw-semibold"><?= e($order['order_number']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><?= e(adminDateTime($order['order_placed_at'])) ?></td>
                                    <td><?= adminStatusBadge($order['order_status']) ?></td>
                                    <td class="text-nowrap">
                                        <?= adminStatusBadge($order['order_payment_status']) ?>
                                        <div class="small text-muted"><?= e(adminPaymentMethodName($order['order_payment_method'])) ?></div>
                                    </td>
                                    <td class="text-end"><?= e(number_format($order['item_count'])) ?></td>
                                    <td class="text-end text-nowrap fw-semibold"><?= e(adminMoney($order['order_total'])) ?></td>
                                    <?php if ($can_view_orders): ?>
                                        <td class="text-end">
                                            <?= adminRowActions(
                                                adminActionLink('bi-eye', 'View order', url('admin/order_details.php?id=' . $order['order_id'])),
                                                adminActionLink('bi-printer', 'Print receipt', url('admin/order_receipt.php?id=' . $order['order_id']), new_tab: true),
                                            ) ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($orders_pages > 1): ?>
                    <div class="admin-panel-footer justify-content-between align-items-center">
                        <span class="small text-muted">Page <?= e($orders_page) ?> of <?= e($orders_pages) ?></span>
                        <div class="d-flex gap-2">
                            <?php if ($orders_page > 1): ?>
                                <a class="btn btn-sm btn-light" href="<?= e(url("admin/customer_details.php?id={$user_id}&orders_page=" . ($orders_page - 1))) ?>"><i class="bi bi-chevron-left"></i> Newer</a>
                            <?php endif; ?>
                            <?php if ($orders_page < $orders_pages): ?>
                                <a class="btn btn-sm btn-light" href="<?= e(url("admin/customer_details.php?id={$user_id}&orders_page=" . ($orders_page + 1))) ?>">Older <i class="bi bi-chevron-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
