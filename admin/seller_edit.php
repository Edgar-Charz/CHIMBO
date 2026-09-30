<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('sellers.manage');
$admin_id      = (int) $current_admin['admin_id'];
$seller_model  = new Seller(Database::instance());

$seller_id = (int) ($_GET['id'] ?? 0);
$is_new    = $seller_id === 0;
$seller    = $is_new ? null : adminLoadOrRedirect(fn () => $seller_model->getSellerForAdmin($seller_id), 'sellers.php');

$form_error = adminHandleForm(function () use ($seller_model, $seller_id, $is_new, $admin_id): void {
    if ($is_new) {
        $seller_model->createSeller($_POST, $admin_id);
        Session::flash('success', 'Seller added.');
    } else {
        $seller_model->updateSeller($seller_id, $_POST, $admin_id);
        Session::flash('success', 'Seller saved.');
    }
    redirect(url('admin/sellers.php'));
});

$errors = $form_error?->fields() ?? [];
$form   = adminFormValues($form_error, $seller ?? ['seller_status' => 'active']);

// Show a saved phone as the admin would type it: "+255712345678" → "+255 712 345 678"
if (!empty($form['seller_phone']) && str_starts_with($form['seller_phone'], '+255')) {
    $form['seller_phone'] = Phone::format($form['seller_phone']);
}

$page_title  = $is_new ? 'Add seller' : $seller['seller_name'];
$active_menu = 'sellers';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/sellers.php')) ?>"><i class="bi bi-arrow-left"></i> Sellers</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<form class="admin-panel admin-form-narrow" method="post" novalidate>
    <?= Csrf::field() ?>
    <div class="admin-panel-header"><h2 class="admin-panel-title">Seller details</h2></div>
    <div class="admin-panel-body">
        <div class="mb-3">
            <label class="form-label" for="seller_name">Name</label>
            <input class="form-control<?= adminInvalidClass($errors, 'seller_name') ?>" id="seller_name" name="seller_name"
                   value="<?= e($form['seller_name'] ?? '') ?>" maxlength="120" required>
            <?= adminFieldError($errors, 'seller_name') ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="seller_phone">Phone <span class="text-muted">(optional)</span></label>
            <input class="form-control<?= adminInvalidClass($errors, 'seller_phone') ?>" type="tel" id="seller_phone" name="seller_phone"
                   value="<?= e($form['seller_phone'] ?? '') ?>" placeholder="0712 345 678">
            <?= adminFieldError($errors, 'seller_phone') ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="seller_description">Description <span class="text-muted">(optional)</span></label>
            <textarea class="form-control<?= adminInvalidClass($errors, 'seller_description') ?>" id="seller_description" name="seller_description"
                      rows="4" maxlength="2000"><?= e($form['seller_description'] ?? '') ?></textarea>
            <?= adminFieldError($errors, 'seller_description') ?>
        </div>

        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="seller_status">Status</label>
                <select class="form-select<?= adminInvalidClass($errors, 'seller_status') ?>" id="seller_status" name="seller_status">
                    <option value="active" <?= adminSelected($form, 'seller_status', 'active') ?>>Active</option>
                    <option value="inactive" <?= adminSelected($form, 'seller_status', 'inactive') ?>>Inactive — hides all their products</option>
                </select>
                <?= adminFieldError($errors, 'seller_status') ?>
            </div>
            <div class="col-sm-6 d-flex align-items-center">
                <div class="form-check form-switch mt-sm-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="seller_is_verified" name="seller_is_verified"
                           value="1" <?= adminChecked($form, 'seller_is_verified') ?>>
                    <label class="form-check-label" for="seller_is_verified">Verified seller</label>
                </div>
            </div>
        </div>
    </div>
    <div class="admin-panel-footer">
        <a class="btn btn-light" href="<?= e(url('admin/sellers.php')) ?>">Cancel</a>
        <button class="btn btn-chimbo" type="submit" name="form_action" value="save"><?= $is_new ? 'Add seller' : 'Save changes' ?></button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
