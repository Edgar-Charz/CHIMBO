<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// Where customers send their money — the most sensitive setting in the shop, so super admin only.
// The five methods are fixed (they can't be added or deleted); unused ones stay switched off.
$current_admin        = AdminSession::requireLogin('payment_methods.manage');
$payment_method_model = new PaymentMethod(Database::instance());

$method_id = (int) ($_GET['id'] ?? 0);
$method    = adminLoadOrRedirect(fn () => $payment_method_model->getMethodById($method_id), 'payments.php?tab=methods');

$form_error = adminHandleForm(function () use ($payment_method_model, $method_id, $current_admin): void {
    $payment_method_model->updateMethod($method_id, $_POST, (int) $current_admin['admin_id']);
    Session::flash('success', 'Payment method saved. Customers see the new details at checkout.');
    redirect(url('admin/payments.php?tab=methods'));
});

$errors  = $form_error?->fields() ?? [];
$form    = adminFormValues($form_error, $method);
$type    = $method['payment_method_type'];
$is_cash = $type === 'cash';
$is_bank = $type === 'bank';

$page_title  = $method['payment_method_name'];
$active_menu = 'payments';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/payments.php?tab=methods')) ?>"><i class="bi bi-arrow-left"></i> Payment methods</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<form class="admin-panel admin-form-narrow" method="post" novalidate>
    <?= Csrf::field() ?>
    <div class="admin-panel-header">
        <h2 class="admin-panel-title"><?= e(ADMIN_PAYMENT_METHOD_TYPES[$type] ?? $type) ?> · <code><?= e($method['payment_method_code']) ?></code></h2>
    </div>
    <div class="admin-panel-body">
        <div class="mb-3">
            <label class="form-label" for="payment_method_name">Name <span class="text-muted">(customers see it)</span></label>
            <input class="form-control<?= adminInvalidClass($errors, 'payment_method_name') ?>" id="payment_method_name" name="payment_method_name"
                   value="<?= e($form['payment_method_name'] ?? '') ?>" maxlength="60" required>
            <?= adminFieldError($errors, 'payment_method_name') ?>
        </div>

        <?php if (!$is_cash): ?>
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label" for="payment_method_account_number"><?= $is_bank ? 'Account number' : 'Lipa Namba or phone number' ?></label>
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
            <?php if ($is_bank): ?>
                <div class="mb-3">
                    <label class="form-label" for="payment_method_bank_name">Bank</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'payment_method_bank_name') ?>" id="payment_method_bank_name"
                           name="payment_method_bank_name" value="<?= e($form['payment_method_bank_name'] ?? '') ?>" maxlength="80" placeholder="e.g. CRDB">
                    <?= adminFieldError($errors, 'payment_method_bank_name') ?>
                </div>
            <?php endif; ?>
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
                data-confirm="Customers will send their money to these details. Are they correct?">Save changes</button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
