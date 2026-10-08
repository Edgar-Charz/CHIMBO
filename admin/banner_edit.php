<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('banners.manage');
$admin_id      = (int) $current_admin['admin_id'];
$banner_model  = new Banner(Database::instance());

$banner_id = (int) ($_GET['id'] ?? 0);
$is_new    = $banner_id === 0;
$banner    = $is_new ? null : adminLoadOrRedirect(fn () => $banner_model->getBannerForAdmin($banner_id), 'banners.php');

$form_error = adminHandleForm(function (string $form_action) use ($banner_model, $banner_id, $is_new, $admin_id): void {
    if ($form_action === 'delete') {
        $banner_model->deleteBanner($banner_id, $admin_id);
        Session::flash('success', 'Banner deleted.');
        redirect(url('admin/banners.php'));
    }
    if ($form_action === 'upload_image') {
        $banner_model->setBannerImage($banner_id, $_FILES['banner_image'] ?? [], $admin_id);
        Session::flash('success', 'Picture updated.');
        redirect(url("admin/banner_edit.php?id={$banner_id}"));
    }
    if ($is_new) {
        $new_banner_id = $banner_model->createBanner($_POST, $admin_id);
        Session::flash('success', 'Banner created. Now add its picture.');
        redirect(url("admin/banner_edit.php?id={$new_banner_id}"));
    }
    $banner_model->updateBanner($banner_id, $_POST, $admin_id);
    Session::flash('success', 'Banner saved.');
    redirect(url("admin/banner_edit.php?id={$banner_id}"));
});

// Saved times are UTC; the datetime inputs show and take Tanzania time
$saved_values = $banner ?? ['banner_is_active' => 1, 'banner_sort_order' => 0];
foreach (['banner_starts_at', 'banner_ends_at'] as $time_field) {
    $saved_values[$time_field] = empty($saved_values[$time_field]) ? '' : adminDateTime($saved_values[$time_field], 'Y-m-d\TH:i');
}

$errors = $form_error?->fields() ?? [];
$form   = adminFormValues($form_error, $saved_values);

$target_types = [
    'category'   => 'A category — enter its id',
    'product'    => 'A product — enter its id',
    'collection' => 'A collection — deals, new, best_sellers or offers',
    'url'        => 'A web address',
];

$page_title  = $is_new ? 'Add banner' : $banner['banner_title'];
$active_menu = 'banners';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/banners.php')) ?>"><i class="bi bi-arrow-left"></i> Banners</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form class="admin-panel" method="post" novalidate>
            <?= Csrf::field() ?>
            <div class="admin-panel-header"><h2 class="admin-panel-title">Banner</h2></div>
            <div class="admin-panel-body">
                <div class="mb-3">
                    <label class="form-label" for="banner_title">Title</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'banner_title') ?>" id="banner_title" name="banner_title"
                           value="<?= e($form['banner_title'] ?? '') ?>" maxlength="100" placeholder="e.g. BEI ZA JUMLA" required>
                    <?= adminFieldError($errors, 'banner_title') ?>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-sm-8">
                        <label class="form-label" for="banner_subtitle">Subtitle <span class="text-muted">(optional)</span></label>
                        <input class="form-control<?= adminInvalidClass($errors, 'banner_subtitle') ?>" id="banner_subtitle" name="banner_subtitle"
                               value="<?= e($form['banner_subtitle'] ?? '') ?>" maxlength="160">
                        <?= adminFieldError($errors, 'banner_subtitle') ?>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="banner_button_label">Button text</label>
                        <input class="form-control<?= adminInvalidClass($errors, 'banner_button_label') ?>" id="banner_button_label" name="banner_button_label"
                               value="<?= e($form['banner_button_label'] ?? '') ?>" maxlength="40" placeholder="e.g. Nunua Sasa">
                        <?= adminFieldError($errors, 'banner_button_label') ?>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="banner_target_type">When tapped, open</label>
                        <select class="form-select<?= adminInvalidClass($errors, 'banner_target_type') ?>" id="banner_target_type" name="banner_target_type">
                            <option value="">Nothing</option>
                            <?php foreach ($target_types as $target_type => $target_label): ?>
                                <option value="<?= e($target_type) ?>" <?= adminSelected($form, 'banner_target_type', $target_type) ?>><?= e($target_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= adminFieldError($errors, 'banner_target_type') ?>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="banner_target_value">Target</label>
                        <input class="form-control<?= adminInvalidClass($errors, 'banner_target_value') ?>" id="banner_target_value" name="banner_target_value"
                               value="<?= e($form['banner_target_value'] ?? '') ?>" maxlength="255" placeholder="e.g. 3, deals or https://…">
                        <?= adminFieldError($errors, 'banner_target_value') ?>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="banner_starts_at">Show from <span class="text-muted">(optional)</span></label>
                        <input class="form-control<?= adminInvalidClass($errors, 'banner_starts_at') ?>" type="datetime-local" id="banner_starts_at"
                               name="banner_starts_at" value="<?= e($form['banner_starts_at'] ?? '') ?>">
                        <?= adminFieldError($errors, 'banner_starts_at') ?>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="banner_ends_at">Show until <span class="text-muted">(optional)</span></label>
                        <input class="form-control<?= adminInvalidClass($errors, 'banner_ends_at') ?>" type="datetime-local" id="banner_ends_at"
                               name="banner_ends_at" value="<?= e($form['banner_ends_at'] ?? '') ?>">
                        <?= adminFieldError($errors, 'banner_ends_at') ?>
                    </div>
                    <div class="col-12 form-text mt-1">Tanzania time. Leave both empty to show the banner all the time.</div>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="banner_sort_order">Display order</label>
                        <input class="form-control<?= adminInvalidClass($errors, 'banner_sort_order') ?>" type="number" min="0" max="1000"
                               id="banner_sort_order" name="banner_sort_order" value="<?= e($form['banner_sort_order'] ?? 0) ?>">
                        <div class="form-text">Lower numbers come first.</div>
                        <?= adminFieldError($errors, 'banner_sort_order') ?>
                    </div>
                    <div class="col-sm-6 d-flex align-items-center">
                        <div class="form-check form-switch mt-sm-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="banner_is_active" name="banner_is_active"
                                   value="1" <?= adminChecked($form, 'banner_is_active') ?>>
                            <label class="form-check-label" for="banner_is_active">Active</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="admin-panel-footer">
                <a class="btn btn-light" href="<?= e(url('admin/banners.php')) ?>">Cancel</a>
                <button class="btn btn-chimbo" type="submit" name="form_action" value="save"><?= $is_new ? 'Create banner' : 'Save changes' ?></button>
            </div>
        </form>
    </div>

    <?php if (!$is_new): ?>
        <div class="col-lg-4">
            <form class="admin-panel mb-3" method="post" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title">Picture</h2></div>
                <div class="admin-panel-body">
                    <?php if ($banner['banner_image_path']): ?>
                        <img class="admin-preview-image admin-content-image-preview mb-3" src="<?= e(url($banner['banner_image_path'])) ?>" alt="">
                    <?php else: ?>
                        <p class="text-muted small">No picture yet. A wide picture works best (about 2:1).</p>
                    <?php endif; ?>
                    <input class="form-control mb-2" type="file" name="banner_image" accept="image/jpeg,image/png,image/webp" required>
                    <div class="form-text mb-3">JPG, PNG or WEBP, up to 8 MB.</div>
                    <button class="btn btn-outline-secondary w-100" type="submit" name="form_action" value="upload_image">
                        <i class="bi bi-upload"></i> <?= $banner['banner_image_path'] ? 'Replace picture' : 'Upload picture' ?>
                    </button>
                </div>
            </form>

            <form class="admin-panel admin-danger-zone" method="post" data-confirm="Delete this banner? This cannot be undone.">
                <?= Csrf::field() ?>
                <div class="admin-panel-body">
                    <h2 class="admin-panel-title mb-1">Delete banner</h2>
                    <p class="small text-muted">To stop showing it for a while, switch off "Active" instead.</p>
                    <button class="btn btn-outline-danger w-100" type="submit" name="form_action" value="delete">Delete banner</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
