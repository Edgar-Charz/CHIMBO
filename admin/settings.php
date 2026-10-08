<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('settings.manage');
$database      = Database::instance();
$settings      = new Settings($database);

$delivery_method_model = new DeliveryMethod($database);
$app_image_model       = new AppImage($database);

// The settings form is saved at once (the class saves only the changed values and writes the audit log).
// The delivery options' enable/disable button is a one-click switch.
// App pictures: upload or go back to the app's own picture (the class checks the file and writes the audit log).
$form_error = adminHandleForm(function (string $form_action) use ($settings, $delivery_method_model, $app_image_model, $current_admin): void {
    $admin_id = (int) $current_admin['admin_id'];
    if ($form_action === 'upload_app_image' || $form_action === 'remove_app_image') {
        $slot = (string) ($_POST['app_image_slot'] ?? '');
        try {
            if ($form_action === 'upload_app_image') {
                $app_image_model->setImage($slot, $_FILES['app_image'] ?? null, $admin_id);
                Session::flash('success', 'Picture uploaded — the apps show it from now on.');
            } else {
                $app_image_model->removeImage($slot, $admin_id);
                Session::flash('success', "The apps show their own picture again.");
            }
        } catch (ApiException $e) {
            // A wrong file has no field of its own on this page, so its message comes back at the top
            Session::flash('error', adminErrorText($e));
        }
        redirect(url('admin/settings.php#app-pictures'));
    }
    if ($form_action === 'toggle_delivery_method') {
        $method_id = adminActionRecordId();
        $method    = $delivery_method_model->getMethodById($method_id);
        $is_active = !$method['delivery_method_is_active'];
        $delivery_method_model->setDeliveryMethodActive($method_id, $is_active, $admin_id);
        Session::flash('success', $method['delivery_method_name'] . ($is_active ? ' is offered at checkout again.' : ' is no longer offered at checkout.'));
        redirect(url('admin/settings.php#delivery-options'));
    }
    $settings->updateSettings($_POST, $admin_id);
    Session::flash('success', 'Settings saved.');
    redirect(url('admin/settings.php'));
});

$setting_groups = $settings->getEditableSettings();
$saved_values   = [];
foreach ($setting_groups as $group_settings) {
    foreach ($group_settings as $setting) {
        $saved_values[$setting['setting_key']] = $setting['setting_value'];
    }
}
$errors = $form_error?->fields() ?? [];
$form   = adminFormValues($form_error, $saved_values);

/** The input for one setting, chosen from its validation rule in Settings::EDITABLE (legal texts are long). */
$input_type = function (string $setting_key): string {
    $rules = [];
    foreach (Settings::EDITABLE as $group_settings) {
        $rules = isset($group_settings[$setting_key]) ? explode('|', $group_settings[$setting_key][1]) : $rules;
    }
    return match (true) {
        str_starts_with($setting_key, 'legal_') => 'textarea',
        in_array('phone_tz', $rules, true)      => 'tel',
        in_array('int', $rules, true)           => 'number',
        default                                 => 'text',
    };
};

$delivery_methods = $delivery_method_model->listForAdmin();
$app_images       = $app_image_model->listForAdmin();

$page_title  = 'Settings';
$active_menu = 'settings';
require __DIR__ . '/includes/header.php';
?>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<form method="post" novalidate>
    <?= Csrf::field() ?>
    <div class="row g-3">
        <?php foreach ($setting_groups as $group_title => $group_settings): ?>
            <?php $is_long_text = $input_type($group_settings[0]['setting_key']) === 'textarea'; ?>
            <div class="<?= $is_long_text ? 'col-12' : 'col-lg-6' ?>">
                <section class="admin-panel h-100">
                    <div class="admin-panel-header"><h2 class="admin-panel-title"><?= e($group_title) ?></h2></div>
                    <div class="admin-panel-body">
                        <?php foreach ($group_settings as $setting): ?>
                            <?php $setting_key = $setting['setting_key']; ?>
                            <div class="mb-3">
                                <label class="form-label" for="<?= e($setting_key) ?>"><?= e($setting['label']) ?></label>
                                <?php if ($input_type($setting_key) === 'textarea'): ?>
                                    <textarea class="form-control settings-long-text<?= adminInvalidClass($errors, $setting_key) ?>" id="<?= e($setting_key) ?>"
                                              name="<?= e($setting_key) ?>" rows="12"><?= e($form[$setting_key] ?? '') ?></textarea>
                                <?php else: ?>
                                    <input class="form-control<?= adminInvalidClass($errors, $setting_key) ?>" type="<?= e($input_type($setting_key)) ?>"
                                           id="<?= e($setting_key) ?>" name="<?= e($setting_key) ?>" value="<?= e($form[$setting_key] ?? '') ?>">
                                <?php endif; ?>
                                <?= adminFieldError($errors, $setting_key) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="admin-save-bar">
        <span class="small text-muted me-auto">Only the values you changed are saved; every change goes to the audit log.</span>
        <button class="btn btn-chimbo" type="submit" name="form_action" value="save">Save settings</button>
    </div>
</form>

<div class="admin-page-actions mt-4" id="delivery-options">
    <p class="text-muted mb-0">Delivery options customers choose at checkout. A fee change applies to new checkouts only.</p>
    <a class="btn btn-chimbo" href="<?= e(url('admin/delivery_method_edit.php')) ?>"><i class="bi bi-plus-lg"></i> Add delivery option</a>
</div>

<div class="admin-panel admin-table-panel">
    <table class="table admin-table w-100" data-datatable data-title="Delivery options" data-icon="bi-truck"
           data-order='[]' data-search-placeholder="Search delivery options" data-empty-message="No delivery options yet.">
        <thead>
            <tr>
                <th>Option</th>
                <th>Region</th>
                <th class="text-end">Fee</th>
                <th class="text-end">Delivery time</th>
                <th class="text-end">Order</th>
                <th>Status</th>
                <th class="text-end" data-orderable="false">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($delivery_methods as $method): ?>
                <tr class="row-link">
                    <td>
                        <a class="stretched-link fw-semibold" href="<?= e(url('admin/delivery_method_edit.php?id=' . $method['delivery_method_id'])) ?>">
                            <?= e($method['delivery_method_name']) ?>
                        </a>
                        <div class="small text-muted"><?= e($method['delivery_method_code']) ?></div>
                    </td>
                    <td><?= e($method['region_name'] ?? 'All regions') ?></td>
                    <td class="text-end text-nowrap" data-order="<?= e($method['delivery_method_fee']) ?>"><?= e(adminMoney((int) $method['delivery_method_fee'])) ?></td>
                    <td class="text-end text-nowrap" data-order="<?= e($method['delivery_method_eta_max_days']) ?>">
                        <?= e($method['delivery_method_eta_min_days']) ?>–<?= e($method['delivery_method_eta_max_days']) ?> days
                    </td>
                    <td class="text-end"><?= e($method['delivery_method_sort_order']) ?></td>
                    <td><?= adminStatusBadge($method['delivery_method_is_active'] ? 'active' : 'hidden') ?></td>
                    <td class="text-end">
                        <?= adminRowActions(
                            adminActionLink('bi-pencil', 'Edit', url('admin/delivery_method_edit.php?id=' . $method['delivery_method_id'])),
                            $method['delivery_method_is_active']
                                ? adminActionButton('bi-pause-circle', 'Stop offering at checkout', 'toggle_delivery_method', (int) $method['delivery_method_id'])
                                : adminActionButton('bi-play-circle', 'Offer at checkout', 'toggle_delivery_method', (int) $method['delivery_method_id']),
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<section class="mt-4" id="app-pictures">
    <div class="admin-page-actions">
        <div>
            <h2 class="h5 mb-1">App pictures</h2>
            <p class="text-muted mb-0">
                Pictures on the app's welcome, login and order screens. Until one is uploaded the app shows its own.
                Home's pictures come from <a href="<?= e(url('admin/categories.php')) ?>">Categories</a> and <a href="<?= e(url('admin/banners.php')) ?>">Banners</a>.
            </p>
        </div>
    </div>
    <div class="row g-3">
        <?php foreach ($app_images as $app_image): ?>
            <?php $slot = $app_image['app_image_slot']; ?>
            <div class="col-sm-6 col-xl-4">
                <div class="admin-panel app-image-card h-100 d-flex flex-column">
                    <div class="app-image-preview">
                        <?php if ($app_image['app_image_path']): ?>
                            <img src="<?= e(url($app_image['app_image_path'])) ?>" alt="<?= e($app_image['label']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="text-muted small"><i class="bi bi-phone"></i> App's own picture</span>
                        <?php endif; ?>
                    </div>
                    <div class="admin-panel-body flex-grow-1">
                        <div class="fw-semibold"><?= e($app_image['label']) ?></div>
                        <div class="small text-muted">Best size: <?= e($app_image['size_advice']) ?></div>
                        <?php if ($app_image['app_image_path']): ?>
                            <div class="small text-muted">Uploaded <?= e(adminDateTime($app_image['updated_at'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="admin-panel-footer flex-column align-items-stretch">
                        <form class="d-flex gap-2" method="post" enctype="multipart/form-data">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="app_image_slot" value="<?= e($slot) ?>">
                            <input class="form-control form-control-sm" type="file" name="app_image" accept="image/jpeg,image/png,image/webp" required
                                   aria-label="Picture for <?= e($app_image['label']) ?>">
                            <button class="btn btn-sm btn-chimbo text-nowrap" type="submit" name="form_action" value="upload_app_image">
                                <i class="bi bi-upload"></i> Upload
                            </button>
                        </form>
                        <?php if ($app_image['app_image_path']): ?>
                            <form method="post" data-confirm="Show the app's own picture here again? The uploaded one is deleted.">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="app_image_slot" value="<?= e($slot) ?>">
                                <button class="btn btn-sm btn-light w-100" type="submit" name="form_action" value="remove_app_image">
                                    <i class="bi bi-arrow-counterclockwise"></i> Use the app's picture
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
