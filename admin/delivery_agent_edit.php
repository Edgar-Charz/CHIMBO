<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('delivery.manage');
$admin_id      = (int) $current_admin['admin_id'];
$agent_model   = new DeliveryAgent(Database::instance());

$agent_id = (int) ($_GET['id'] ?? 0);
$is_new   = $agent_id === 0;
$agent    = $is_new ? null : adminLoadOrRedirect(fn () => $agent_model->getAgentForAdmin($agent_id), 'delivery_agents.php');

$form_error = adminHandleForm(function (string $form_action) use ($agent_model, $agent_id, $is_new, $admin_id): void {
    if ($form_action === 'upload_photo') {
        $agent_model->setAgentPhoto($agent_id, $_FILES['delivery_agent_photo'] ?? [], $admin_id);
        Session::flash('success', 'Photo updated.');
        redirect(url("admin/delivery_agent_edit.php?id={$agent_id}"));
    }
    if ($is_new) {
        $new_agent_id = $agent_model->createAgent($_POST, $admin_id);
        Session::flash('success', 'Delivery agent added. You can add their photo now.');
        redirect(url("admin/delivery_agent_edit.php?id={$new_agent_id}"));
    }
    $agent_model->updateAgent($agent_id, $_POST, $admin_id);
    Session::flash('success', 'Delivery agent saved.');
    redirect(url('admin/delivery_agents.php'));
});

$errors = $form_error?->fields() ?? [];
$form   = adminFormValues($form_error, $agent ?? ['delivery_agent_is_active' => 1]);

// Show a saved phone as the admin would type it: "+255712345678" → "+255 712 345 678"
if (!empty($form['delivery_agent_phone']) && str_starts_with($form['delivery_agent_phone'], '+255')) {
    $form['delivery_agent_phone'] = Phone::format($form['delivery_agent_phone']);
}

$page_title  = $is_new ? 'Add delivery agent' : $agent['delivery_agent_full_name'];
$active_menu = 'delivery_agents';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/delivery_agents.php')) ?>"><i class="bi bi-arrow-left"></i> Delivery agents</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form class="admin-panel" method="post" novalidate>
            <?= Csrf::field() ?>
            <div class="admin-panel-header"><h2 class="admin-panel-title">Agent details</h2></div>
            <div class="admin-panel-body">
                <div class="mb-3">
                    <label class="form-label" for="delivery_agent_full_name">Full name</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'delivery_agent_full_name') ?>" id="delivery_agent_full_name" name="delivery_agent_full_name"
                           value="<?= e($form['delivery_agent_full_name'] ?? '') ?>" maxlength="100" required>
                    <?= adminFieldError($errors, 'delivery_agent_full_name') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="delivery_agent_phone">Phone</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'delivery_agent_phone') ?>" type="tel" id="delivery_agent_phone" name="delivery_agent_phone"
                           value="<?= e($form['delivery_agent_phone'] ?? '') ?>" placeholder="0712 345 678" required>
                    <div class="form-text">Customers call this number while their order is on the way.</div>
                    <?= adminFieldError($errors, 'delivery_agent_phone') ?>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="delivery_agent_is_active" name="delivery_agent_is_active"
                           value="1" <?= adminChecked($form, 'delivery_agent_is_active') ?>>
                    <label class="form-check-label" for="delivery_agent_is_active">Active — can be chosen when dispatching</label>
                </div>
            </div>
            <div class="admin-panel-footer">
                <a class="btn btn-light" href="<?= e(url('admin/delivery_agents.php')) ?>">Cancel</a>
                <button class="btn btn-chimbo" type="submit" name="form_action" value="save"><?= $is_new ? 'Add agent' : 'Save changes' ?></button>
            </div>
        </form>
    </div>

    <?php if (!$is_new): ?>
        <div class="col-lg-4">
            <form class="admin-panel" method="post" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title">Photo</h2></div>
                <div class="admin-panel-body">
                    <?php if ($agent['delivery_agent_photo_path']): ?>
                        <img class="admin-preview-image mb-3" src="<?= e(url($agent['delivery_agent_photo_path'])) ?>" alt="">
                    <?php else: ?>
                        <p class="text-muted small">No photo yet. Customers see it on the order tracking screen.</p>
                    <?php endif; ?>
                    <input class="form-control mb-2" type="file" name="delivery_agent_photo" accept="image/jpeg,image/png,image/webp" required>
                    <div class="form-text mb-3">JPG, PNG or WEBP, up to 8 MB.</div>
                    <button class="btn btn-outline-secondary w-100" type="submit" name="form_action" value="upload_photo">
                        <i class="bi bi-upload"></i> <?= $agent['delivery_agent_photo_path'] ? 'Replace photo' : 'Upload photo' ?>
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
