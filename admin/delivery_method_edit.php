<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin   = AdminSession::requireLogin('settings.manage');
$admin_id        = (int) $current_admin['admin_id'];
$database        = Database::instance();
$delivery_method = new DeliveryMethod($database);

$method_id = (int) ($_GET['id'] ?? 0);
$is_new    = $method_id === 0;
$method    = $is_new ? null : adminLoadOrRedirect(fn () => $delivery_method->getMethodById($method_id), 'settings.php');

$form_error = adminHandleForm(function () use ($delivery_method, $method_id, $is_new, $admin_id): void {
    if ($is_new) {
        $delivery_method->createMethod($_POST, $admin_id);
        Session::flash('success', 'Delivery option added.');
    } else {
        $delivery_method->updateMethod($method_id, $_POST, $admin_id);
        Session::flash('success', 'Delivery option saved. New checkouts use it from now on.');
    }
    redirect(url('admin/settings.php'));
});

$errors  = $form_error?->fields() ?? [];
$form    = adminFormValues($form_error, $method ?? ['delivery_method_is_active' => 1, 'delivery_method_sort_order' => 0]);
$regions = (new Region($database))->getAllRegions();

$page_title  = $is_new ? 'Add delivery option' : $method['delivery_method_name'];
$active_menu = 'settings';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/settings.php')) ?>"><i class="bi bi-arrow-left"></i> Settings</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<form class="admin-panel admin-form-narrow" method="post" novalidate>
    <?= Csrf::field() ?>
    <div class="admin-panel-header"><h2 class="admin-panel-title">Delivery option</h2></div>
    <div class="admin-panel-body">
        <div class="mb-3">
            <label class="form-label" for="delivery_method_name">Name <span class="text-muted">(customers see it, Kiswahili)</span></label>
            <input class="form-control<?= adminInvalidClass($errors, 'delivery_method_name') ?>" id="delivery_method_name" name="delivery_method_name"
                   value="<?= e($form['delivery_method_name'] ?? '') ?>" maxlength="80" placeholder="e.g. Haraka (kesho)" required>
            <?= adminFieldError($errors, 'delivery_method_name') ?>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label class="form-label" for="delivery_method_fee">Fee</label>
                <div class="input-group">
                    <span class="input-group-text">TZS</span>
                    <input class="form-control<?= adminInvalidClass($errors, 'delivery_method_fee') ?>" type="number" min="0" id="delivery_method_fee"
                           name="delivery_method_fee" value="<?= e($form['delivery_method_fee'] ?? '') ?>" required>
                </div>
                <?= adminFieldError($errors, 'delivery_method_fee') ?>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="region_id">Region</label>
                <select class="form-select<?= adminInvalidClass($errors, 'region_id') ?>" id="region_id" name="region_id">
                    <option value="">All regions</option>
                    <?php foreach ($regions as $region): ?>
                        <option value="<?= e($region['region_id']) ?>" <?= adminSelected($form, 'region_id', $region['region_id']) ?>><?= e($region['region_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= adminFieldError($errors, 'region_id') ?>
            </div>
        </div>

        <label class="form-label">Delivery time (days)</label>
        <div class="input-group admin-input-medium">
            <input class="form-control<?= adminInvalidClass($errors, 'delivery_method_eta_min_days') ?>" type="number" min="0" max="60"
                   name="delivery_method_eta_min_days" value="<?= e($form['delivery_method_eta_min_days'] ?? '') ?>" aria-label="Earliest day">
            <span class="input-group-text">to</span>
            <input class="form-control<?= adminInvalidClass($errors, 'delivery_method_eta_max_days') ?>" type="number" min="0" max="60"
                   name="delivery_method_eta_max_days" value="<?= e($form['delivery_method_eta_max_days'] ?? '') ?>" aria-label="Latest day">
        </div>
        <?= adminFieldError($errors, 'delivery_method_eta_min_days') ?>
        <?= adminFieldError($errors, 'delivery_method_eta_max_days') ?>

        <div class="row g-3 mt-1">
            <div class="col-sm-6">
                <label class="form-label" for="delivery_method_sort_order">Display order</label>
                <input class="form-control<?= adminInvalidClass($errors, 'delivery_method_sort_order') ?>" type="number" min="0" max="1000"
                       id="delivery_method_sort_order" name="delivery_method_sort_order" value="<?= e($form['delivery_method_sort_order'] ?? 0) ?>">
                <div class="form-text">Lower numbers come first.</div>
                <?= adminFieldError($errors, 'delivery_method_sort_order') ?>
            </div>
            <div class="col-sm-6 d-flex align-items-center">
                <div class="form-check form-switch mt-sm-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="delivery_method_is_active" name="delivery_method_is_active"
                           value="1" <?= adminChecked($form, 'delivery_method_is_active') ?>>
                    <label class="form-check-label" for="delivery_method_is_active">Offered at checkout</label>
                </div>
            </div>
        </div>
    </div>
    <div class="admin-panel-footer">
        <a class="btn btn-light" href="<?= e(url('admin/settings.php')) ?>">Cancel</a>
        <button class="btn btn-chimbo" type="submit" name="form_action" value="save"><?= $is_new ? 'Add option' : 'Save changes' ?></button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
