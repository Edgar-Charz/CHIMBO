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
    if (!$is_new && ($_POST['form_action'] ?? '') === 'upload_icon') {
        $payment_method_model->setIcon($method_id, $_FILES['payment_method_icon'] ?? null, $admin_id);
        Session::flash('success', 'Payment method icon uploaded.');
        redirect(url('admin/payment_method_edit.php?id=' . $method_id));
    }
    if (!$is_new && ($_POST['form_action'] ?? '') === 'remove_icon') {
        $payment_method_model->removeIcon($method_id, $admin_id);
        Session::flash('success', 'Payment method icon removed. Customers will see the built-in icon.');
        redirect(url('admin/payment_method_edit.php?id=' . $method_id));
    }
    if ($is_new) {
        $payment_method_model->createMethod($_POST, $admin_id, $_FILES['payment_method_icon'] ?? null);
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

<?php if (!$is_new): ?>
    <section class="admin-panel admin-form-narrow mb-4">
        <div class="admin-panel-header"><h2 class="admin-panel-title">Customer icon</h2></div>
        <div class="admin-panel-body d-flex align-items-center gap-3">
            <?php if ($method['payment_method_icon_path'] !== null): ?>
                <img src="<?= e(url($method['payment_method_icon_path'])) ?>" alt="<?= e($method['payment_method_name']) ?> icon"
                     width="64" height="64" class="rounded border bg-white object-fit-contain">
            <?php else: ?>
                <div class="payment-method-icon-fallback rounded border bg-light d-flex align-items-center justify-content-center text-success" aria-hidden="true">
                    <i class="bi <?= $type === 'bank' ? 'bi-bank' : 'bi-phone' ?> fs-3"></i>
                </div>
            <?php endif; ?>
            <div>
                <div class="fw-semibold"><?= $method['payment_method_icon_path'] === null ? 'Using the built-in type icon' : 'Uploaded icon is active' ?></div>
                <div class="small text-muted">Upload a JPG, PNG or WebP logo. It will be resized to a square PNG.</div>
            </div>
        </div>
        <div class="admin-panel-footer d-flex flex-wrap gap-2">
            <form method="post" enctype="multipart/form-data" class="d-flex flex-grow-1 align-items-center gap-2">
                <?= Csrf::field() ?>
                <input class="form-control form-control-sm" type="file" name="payment_method_icon" accept="image/jpeg,image/png,image/webp" required
                       aria-label="Icon for <?= e($method['payment_method_name']) ?>">
                <button class="btn btn-sm btn-chimbo text-nowrap" type="submit" name="form_action" value="upload_icon">Upload icon</button>
            </form>
            <?php if ($method['payment_method_icon_path'] !== null): ?>
                <form method="post">
                    <?= Csrf::field() ?>
                    <button class="btn btn-sm btn-light" type="submit" name="form_action" value="remove_icon"
                            data-confirm="Remove this uploaded icon? Customers will see the built-in type icon.">Use built-in icon</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<form class="admin-panel admin-form-narrow" method="post" enctype="multipart/form-data" novalidate>
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

        <?php if ($is_new): ?>
            <div class="mb-3">
                <label class="form-label" for="payment_method_icon">Payment icon <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="payment_method_icon" type="file" name="payment_method_icon" accept="image/jpeg,image/png,image/webp">
                <div class="form-text">Upload a mobile network or bank logo. JPG, PNG or WebP; it will be resized to a square PNG. If omitted, customers see the built-in type icon.</div>
                <?= adminFieldError($errors, 'payment_method_icon') ?>
            </div>
        <?php endif; ?>

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
