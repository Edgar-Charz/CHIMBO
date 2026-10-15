<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// Where customers send their money — the most sensitive setting in the shop, so super admin only.
// Staff can add mobile money and bank methods (e.g. HaloPesa, NMB Bank); a method no longer used is switched off,
// not deleted (old orders still name it). Cash on delivery is built in, once.
$current_admin        = AdminSession::requireLogin('payment_methods.manage');
$payment_method_model = new PaymentMethod(Database::instance());

$method_id = (int) ($_GET['id'] ?? 0);
$is_new    = $method_id === 0;
$method    = $is_new ? null : adminLoadOrRedirect(fn () => $payment_method_model->getMethodById($method_id), 'payments.php?tab=methods');

$form_error = adminHandleForm(function () use ($payment_method_model, $method_id, $is_new, $current_admin): void {
    $admin_id = (int) $current_admin['admin_id'];
    if ($is_new) {
        $payment_method_model->createMethod($_POST, $admin_id);
        Session::flash('success', 'Payment method added. Switch it on when its details are right.');
    } else {
        $payment_method_model->updateMethod($method_id, $_POST, $admin_id);
        Session::flash('success', 'Payment method saved. Customers see the new details at checkout.');
    }
    redirect(url('admin/payments.php?tab=methods'));
});

$errors  = $form_error?->fields() ?? [];
$form    = adminFormValues($form_error, $method ?? ['payment_method_type' => 'mobile_money', 'payment_method_is_active' => 0, 'payment_method_sort_order' => 10]);
$type    = $form['payment_method_type'] ?? 'mobile_money';   // a new one: the type chosen in the form
$is_cash = !$is_new && $type === 'cash';

$page_title  = $is_new ? 'Add payment method' : $method['payment_method_name'];
$active_menu = 'payments';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/payments.php?tab=methods')) ?>"><i class="bi bi-arrow-left"></i> Payment methods</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<form class="admin-panel admin-form-narrow" method="post" novalidate>
    <?= Csrf::field() ?>
    <div class="admin-panel-header">
        <h2 class="admin-panel-title">
            <?= $is_new ? 'New payment method' : e(ADMIN_PAYMENT_METHOD_TYPES[$type] ?? $type) . ' · <code>' . e($method['payment_method_code']) . '</code>' ?>
        </h2>
    </div>
    <div class="admin-panel-body">
        <?php if (!$is_new): ?>
            <input type="hidden" name="payment_method_type" value="<?= e($type) ?>">   <?php // read by data-show-when only ?>
        <?php else: ?>
            <div class="mb-3">
                <label class="form-label" for="payment_method_type">Type</label>
                <select class="form-select<?= adminInvalidClass($errors, 'payment_method_type') ?>" id="payment_method_type" name="payment_method_type">
                    <?php foreach (PaymentMethod::ADDABLE_TYPES as $type_code => $type_name): ?>
                        <option value="<?= e($type_code) ?>" <?= adminSelected($form, 'payment_method_type', $type_code) ?>><?= e($type_name) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Customers pay first and press "Nimelipa"; staff check it on the Payments page. The type can't be changed later.</div>
                <?= adminFieldError($errors, 'payment_method_type') ?>
            </div>
        <?php endif; ?>

        <div class="mb-3">
            <label class="form-label" for="payment_method_name">Name <span class="text-muted">(customers see it)</span></label>
            <input class="form-control<?= adminInvalidClass($errors, 'payment_method_name') ?>" id="payment_method_name" name="payment_method_name"
                   value="<?= e($form['payment_method_name'] ?? '') ?>" maxlength="60" required placeholder="e.g. HaloPesa or NMB Bank">
            <?= adminFieldError($errors, 'payment_method_name') ?>
        </div>

        <?php if (!$is_cash): ?>
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label" for="payment_method_account_number">
                        <span data-show-when="payment_method_type=bank">Account number</span>
                        <span data-show-when="payment_method_type!=bank">Lipa Namba or phone number</span>
                    </label>
                    <input class="form-control payment-code<?= adminInvalidClass($errors, 'payment_method_account_number') ?>" id="payment_method_account_number"
                           name="payment_method_account_number" value="<?= e($form['payment_method_account_number'] ?? '') ?>" maxlength="40" autocomplete="off">
                    <?= adminFieldError($errors, 'payment_method_account_number') ?>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="payment_method_account_name">Account name</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'payment_method_account_name') ?>" id="payment_method_account_name"
                           name="payment_method_account_name" value="<?= e($form['payment_method_account_name'] ?? '') ?>" maxlength="100" placeholder="e.g. CHIMBO LTD">
                    <div class="form-text">The name customers see on their phone or bank before they send the money.</div>
                    <?= adminFieldError($errors, 'payment_method_account_name') ?>
                </div>
            </div>
            <div class="mb-3" data-show-when="payment_method_type=bank">
                <label class="form-label" for="payment_method_bank_name">Bank</label>
                <input class="form-control<?= adminInvalidClass($errors, 'payment_method_bank_name') ?>" id="payment_method_bank_name"
                       name="payment_method_bank_name" value="<?= e($form['payment_method_bank_name'] ?? '') ?>" maxlength="80" placeholder="e.g. CRDB">
                <?= adminFieldError($errors, 'payment_method_bank_name') ?>
            </div>
        <?php endif; ?>

        <div class="mb-3">
            <label class="form-label" for="payment_method_instructions">Instructions <span class="text-muted">(optional, Kiswahili)</span></label>
            <textarea class="form-control<?= adminInvalidClass($errors, 'payment_method_instructions') ?>" id="payment_method_instructions"
                      name="payment_method_instructions" rows="3" maxlength="500"><?= e($form['payment_method_instructions'] ?? '') ?></textarea>
            <div class="form-text">Extra steps shown to the customer, e.g. "Andika namba ya oda kama kumbukumbu." Up to 500 characters.</div>
            <?= adminFieldError($errors, 'payment_method_instructions') ?>
        </div>

        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="payment_method_sort_order">Display order</label>
                <input class="form-control<?= adminInvalidClass($errors, 'payment_method_sort_order') ?>" type="number" min="0" max="1000"
                       id="payment_method_sort_order" name="payment_method_sort_order" value="<?= e($form['payment_method_sort_order'] ?? 0) ?>">
                <div class="form-text">Lower numbers come first at checkout.</div>
                <?= adminFieldError($errors, 'payment_method_sort_order') ?>
            </div>
            <div class="col-sm-6 d-flex align-items-center">
                <div class="form-check form-switch mt-sm-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="payment_method_is_active" name="payment_method_is_active"
                           value="1" <?= adminChecked($form, 'payment_method_is_active') ?>>
                    <label class="form-check-label" for="payment_method_is_active">Offered at checkout</label>
                </div>
            </div>
        </div>
        <?= adminFieldError($errors, 'payment_method_is_active') ?>
    </div>
    <div class="admin-panel-footer">
        <a class="btn btn-light" href="<?= e(url('admin/payments.php?tab=methods')) ?>">Cancel</a>
        <button class="btn btn-chimbo" type="submit" name="form_action" value="save"
                data-confirm="Customers will send their money to these details. Are they correct?"><?= $is_new ? 'Add method' : 'Save changes' ?></button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
