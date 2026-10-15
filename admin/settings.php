<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('settings.manage');
$database      = Database::instance();
$settings      = new Settings($database);

$delivery_method_model = new DeliveryMethod($database);
$app_image_model       = new AppImage($database);
$site_logo             = new SiteLogo($database);

// One tab per subject. A tab with settings lists its groups from Settings::EDITABLE; its form saves only those.
$tabs = [
    'general'  => ['General',            'bi-sliders',       ['Support contacts', 'Pickup point', 'Legal texts (Kiswahili)']],
    'orders'   => ['Orders & payments',  'bi-receipt',       ['Orders']],
    'delivery' => ['Delivery',           'bi-truck',         []],
    'login'    => ['Login (SMS)',        'bi-shield-lock',   ['Login codes (SMS)']],
    'pictures' => ['Logo & pictures',    'bi-image',         []],
];
$tab      = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'general';
$this_tab = url("admin/settings.php?tab={$tab}");

// Settings: the class saves only the changed values of this tab's groups and writes the audit log.
// Delivery: an option's enable/disable is a one-click switch; the region estimates are one form.
// App pictures and the logo: upload or go back to the built-in one (the classes check the file and write the audit log).
$form_error = adminHandleForm(function (string $form_action) use ($settings, $delivery_method_model, $app_image_model, $site_logo, $current_admin, $tabs, $tab, $this_tab): void {
    $admin_id = (int) $current_admin['admin_id'];
    if ($form_action === 'upload_logo' || $form_action === 'remove_logo') {
        try {
            if ($form_action === 'upload_logo') {
                $site_logo->setLogo($_FILES['logo'] ?? null, $admin_id);
                Session::flash('success', 'Logo uploaded — the website, admin, receipts and app screens use it now.');
            } else {
                $site_logo->removeLogo($admin_id);
                Session::flash('success', 'The built-in CHIMBO logo is back everywhere.');
            }
        } catch (ApiException $e) {
            Session::flash('error', adminErrorText($e));
        }
        redirect($this_tab);
    }
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
        redirect($this_tab);
    }
    if ($form_action === 'toggle_delivery_method') {
        $method_id = adminActionRecordId();
        $method    = $delivery_method_model->getMethodById($method_id);
        $is_active = !$method['delivery_method_is_active'];
        $delivery_method_model->setDeliveryMethodActive($method_id, $is_active, $admin_id);
        Session::flash('success', $method['delivery_method_name'] . ($is_active ? ' is offered at checkout again.' : ' is no longer offered at checkout.'));
        redirect($this_tab);
    }
    if ($form_action === 'save_estimates') {
        $delivery_method_model->saveRegionEstimates((array) ($_POST['region_transport_estimate'] ?? []), $admin_id);
        Session::flash('success', 'Transport estimates saved. New checkouts show them; placed orders keep their price.');
        redirect($this_tab . '#transport-estimates');
    }
    $settings->updateSettings($_POST, $admin_id, $tabs[$tab][2]);
    Session::flash('success', 'Settings saved.');
    redirect($this_tab);
});
$errors = $form_error?->fields() ?? [];

$setting_groups = array_intersect_key($settings->getEditableSettings(), array_flip($tabs[$tab][2]));
$saved_values   = [];
foreach ($setting_groups as $group_settings) {
    foreach ($group_settings as $setting) {
        $saved_values[$setting['setting_key']] = $setting['setting_value'];
    }
}
$form = adminFormValues($form_error, $saved_values);

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

if ($tab === 'delivery') {
    $delivery_methods = $delivery_method_model->listForAdmin();
    $region_estimates = $delivery_method_model->getRegionEstimates();
    // After a failed save the typed amounts come back; otherwise the saved ones
    $estimate_values  = $form_error !== null && ($_POST['form_action'] ?? '') === 'save_estimates'
        ? (array) ($_POST['region_transport_estimate'] ?? [])
        : array_column($region_estimates, 'region_transport_estimate', 'region_id');
}
if ($tab === 'pictures') {
    $app_images = $app_image_model->listForAdmin();
}

/** Where a delivery option goes, in words: "Every region", "Only Dar es Salaam", "Every region except Dar es Salaam". */
$where_text = fn (array $method): string => match ($method['delivery_method_area']) {
    'only'   => 'Only ' . $method['region_name'],
    'except' => 'Every region except ' . $method['region_name'],
    default  => 'Every region',
};

/** The price column: the fee, "Region's estimate", "Paid to the rider" or "Free · pickup". */
$price_text = fn (array $method): string => match (true) {
    $method['delivery_method_pricing'] === 'region_estimate' => '<span class="text-muted">Region\'s estimate</span>',
    $method['delivery_method_pricing'] === 'paid_to_rider'   => '<span class="text-muted">Paid to the rider</span>',
    (bool) $method['delivery_method_is_pickup']              => '<span class="text-muted">Free · pickup</span>',
    default                                                  => e(adminMoney((int) $method['delivery_method_fee'])),
};

$page_title  = 'Settings';
$active_menu = 'settings';
require __DIR__ . '/includes/header.php';
?>

<nav class="page-tabs mb-3" aria-label="Settings sections">
    <?php foreach ($tabs as $tab_key => [$tab_name, $tab_icon]): ?>
        <a class="page-tab <?= $tab_key === $tab ? 'active' : '' ?>" href="<?= e(url("admin/settings.php?tab={$tab_key}")) ?>"
           <?= $tab_key === $tab ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= e($tab_icon) ?>"></i> <?= e($tab_name) ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<?php if ($setting_groups !== []): ?>
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

            <?php if ($tab === 'orders'): ?>
                <div class="col-lg-6">
                    <section class="admin-panel h-100">
                        <div class="admin-panel-header"><h2 class="admin-panel-title">Payment methods</h2></div>
                        <div class="admin-panel-body">
                            <p class="text-muted">Which ways to pay customers see, and the account numbers they pay to.</p>
                            <a class="btn btn-light" href="<?= e(url('admin/payments.php?tab=methods')) ?>"><i class="bi bi-wallet2"></i> Open payment methods</a>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        </div>

        <div class="admin-save-bar">
            <span class="small text-muted me-auto">Only the values you changed are saved; every change goes to the audit log.</span>
            <button class="btn btn-chimbo" type="submit" name="form_action" value="save">Save settings</button>
        </div>
    </form>
<?php endif; ?>

<?php if ($tab === 'delivery'): ?>
    <div class="admin-page-actions">
        <p class="text-muted mb-0">
            Delivery options customers choose at checkout. A fixed fee is charged as it is; a region's estimate is
            confirmed by staff after measuring the load, and only then the customer pays; "paid to the rider" (Bolt) is
            never in CHIMBO's total. Pickup options are collected at the shop (address in General). Changes apply to new checkouts only.
        </p>
        <a class="btn btn-chimbo" href="<?= e(url('admin/delivery_method_edit.php')) ?>"><i class="bi bi-plus-lg"></i> Add delivery option</a>
    </div>

    <div class="admin-panel admin-table-panel">
        <table class="table admin-table w-100" data-datatable data-title="Delivery options" data-icon="bi-truck"
               data-order='[]' data-search-placeholder="Search delivery options" data-empty-message="No delivery options yet.">
            <thead>
                <tr>
                    <th>Option</th>
                    <th>Where</th>
                    <th class="text-end">Price</th>
                    <th class="text-end">Delivery time</th>
                    <th class="text-end">Order</th>
                    <th>Status</th>
                    <th class="text-end" data-orderable="false">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($delivery_methods as $method): ?>
                    <tr data-href="<?= e(url('admin/delivery_method_edit.php?id=' . $method['delivery_method_id'])) ?>">
                        <td>
                            <span class="fw-semibold"><?= e($method['delivery_method_name']) ?></span>
                            <div class="small text-muted"><?= e($method['delivery_method_code']) ?></div>
                        </td>
                        <td><?= e($where_text($method)) ?></td>
                        <td class="text-end text-nowrap" data-order="<?= e($method['delivery_method_pricing'] === 'fixed' ? $method['delivery_method_fee'] : -1) ?>">
                            <?= $price_text($method) ?>
                        </td>
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

    <form class="admin-panel mt-4" id="transport-estimates" method="post" novalidate>
        <?= Csrf::field() ?>
        <div class="admin-panel-header">
            <h2 class="admin-panel-title">Upcountry transport estimates</h2>
        </div>
        <div class="admin-panel-body">
            <p class="text-muted">
                The usual transport price to each region, shown at checkout for options priced by the region's estimate.
                Leave a region empty when there is no usual price: the customer sees "tutakujulisha bei" and staff set it after measuring.
            </p>
            <div class="row g-3">
                <?php foreach ($region_estimates as $region): ?>
                    <?php $field = "region_transport_estimate[{$region['region_id']}]"; ?>
                    <div class="col-sm-6 col-lg-4 col-xl-3">
                        <label class="form-label small mb-1" for="estimate-<?= e($region['region_id']) ?>"><?= e($region['region_name']) ?></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">TZS</span>
                            <input class="form-control<?= adminInvalidClass($errors, $field) ?>" type="number" min="0" step="500"
                                   id="estimate-<?= e($region['region_id']) ?>" name="<?= e($field) ?>"
                                   value="<?= e($estimate_values[$region['region_id']] ?? '') ?>" placeholder="No estimate">
                        </div>
                        <?= adminFieldError($errors, $field) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="admin-panel-footer">
            <span class="small text-muted me-auto">Placed orders keep the price they were given; every change goes to the audit log.</span>
            <button class="btn btn-chimbo" type="submit" name="form_action" value="save_estimates">Save estimates</button>
        </div>
    </form>
<?php endif; ?>

<?php if ($tab === 'pictures'): ?>
    <section class="admin-panel mb-4" aria-labelledby="logo-title">
        <div class="admin-panel-header"><h2 class="admin-panel-title" id="logo-title">Logo</h2></div>
        <div class="admin-panel-body d-flex flex-wrap align-items-center gap-4">
            <div class="logo-preview">
                <img src="<?= e($site_logo->getUrl() ?? adminAsset('img/favicon-192.png')) ?>" alt="Current logo">
            </div>
            <div class="flex-grow-1">
                <p class="mb-1">
                    <?= $site_logo->getUrl() !== null
                        ? 'Uploaded ' . e(adminDateTime($site_logo->updatedAt())) . '.'
                        : 'The built-in CHIMBO logo is used.' ?>
                    Shown on the website (header, footer, browser tab), the admin's browser tab, receipts and packing
                    slips, and the app's splash and login screens.
                </p>
                <p class="small text-muted mb-3">
                    Best: a square PNG, 1024 × 1024, transparent background (a wider logo is centred). The app's icon on
                    the phone's home screen changes only with the next app release.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <form class="d-flex gap-2" method="post" enctype="multipart/form-data">
                        <?= Csrf::field() ?>
                        <input class="form-control form-control-sm" type="file" name="logo" accept="image/png,image/webp,image/jpeg" required aria-label="Logo picture">
                        <button class="btn btn-sm btn-chimbo text-nowrap" type="submit" name="form_action" value="upload_logo">
                            <i class="bi bi-upload"></i> Upload logo
                        </button>
                    </form>
                    <?php if ($site_logo->getUrl() !== null): ?>
                        <form method="post" data-confirm="Go back to the built-in CHIMBO logo everywhere? The uploaded one is deleted.">
                            <?= Csrf::field() ?>
                            <button class="btn btn-sm btn-light" type="submit" name="form_action" value="remove_logo">
                                <i class="bi bi-arrow-counterclockwise"></i> Use the built-in logo
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <h2 class="h5">App pictures</h2>
    <p class="text-muted">
        Pictures on the app's welcome, login and order screens. Until one is uploaded the app shows its own.
        Home's pictures come from <a href="<?= e(url('admin/categories.php')) ?>">Categories</a> and <a href="<?= e(url('admin/banners.php')) ?>">Banners</a>.
    </p>
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
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
