<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('admins.manage');
$admin_id      = (int) $current_admin['admin_id'];
$admin_user    = new AdminUser(Database::instance());

$edited_admin_id = (int) ($_GET['id'] ?? 0);
$is_new          = $edited_admin_id === 0;
$is_own_account  = $edited_admin_id === $admin_id;
$edited_admin    = $is_new ? null : adminLoadOrRedirect(fn () => $admin_user->getAdminById($edited_admin_id), 'admin_users.php');

// The class refuses disabling or demoting yourself and removing the last active super admin, and writes the audit log
$form_error = adminHandleForm(function (string $form_action) use ($admin_user, $edited_admin_id, $is_new, $admin_id): void {
    if ($form_action === 'reset_password') {
        $admin_user->resetPassword($edited_admin_id, $_POST, $admin_id);
        Session::flash('success', 'Password changed. Give the new password to the staff member in person.');
        redirect(url("admin/admin_user_edit.php?id={$edited_admin_id}"));
    }
    if ($is_new) {
        $admin_user->createAdmin($_POST, $admin_id);
        Session::flash('success', 'Staff account created.');
    } else {
        $admin_user->updateAdmin($edited_admin_id, $_POST, $admin_id);
        Session::flash('success', 'Staff account saved.');
    }
    redirect(url('admin/admin_users.php'));
});

$errors          = $form_error?->fields() ?? [];
$form            = adminFormValues($form_error, $edited_admin ?? ['admin_role' => 'operations', 'admin_status' => 'active']);
$password_failed = $form_error !== null && ($_POST['form_action'] ?? '') === 'reset_password';

$page_title  = $is_new ? 'Add staff account' : $edited_admin['admin_full_name'];
$active_menu = 'admin_users';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/admin_users.php')) ?>"><i class="bi bi-arrow-left"></i> Admin users</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <form class="admin-panel" method="post" novalidate>
            <?= Csrf::field() ?>
            <div class="admin-panel-header"><h2 class="admin-panel-title">Account</h2></div>
            <div class="admin-panel-body">
                <div class="mb-3">
                    <label class="form-label" for="admin_full_name">Full name</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'admin_full_name') ?>" id="admin_full_name" name="admin_full_name"
                           value="<?= e($form['admin_full_name'] ?? '') ?>" maxlength="100" required>
                    <?= adminFieldError($errors, 'admin_full_name') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="admin_email">Email <span class="text-muted">(used to log in)</span></label>
                    <input class="form-control<?= adminInvalidClass($errors, 'admin_email') ?>" type="email" id="admin_email" name="admin_email"
                           value="<?= e($form['admin_email'] ?? '') ?>" maxlength="150" autocomplete="off" required>
                    <?= adminFieldError($errors, 'admin_email') ?>
                </div>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="admin_role">Role</label>
                        <select class="form-select<?= adminInvalidClass($errors, 'admin_role') ?>" id="admin_role" name="admin_role">
                            <?php foreach (Admin::ROLE_NAMES as $role => $role_name): ?>
                                <option value="<?= e($role) ?>" <?= adminSelected($form, 'admin_role', $role) ?>><?= e($role_name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= adminFieldError($errors, 'admin_role') ?>
                    </div>
                    <?php if (!$is_new): ?>
                        <div class="col-sm-6">
                            <label class="form-label" for="admin_status">Status</label>
                            <select class="form-select<?= adminInvalidClass($errors, 'admin_status') ?>" id="admin_status" name="admin_status">
                                <option value="active" <?= adminSelected($form, 'admin_status', 'active') ?>>Active</option>
                                <option value="disabled" <?= adminSelected($form, 'admin_status', 'disabled') ?>>Disabled — cannot log in</option>
                            </select>
                            <?= adminFieldError($errors, 'admin_status') ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($is_own_account): ?>
                    <div class="form-text mt-2">This is your own account: you can't disable it or take away your own super admin role.</div>
                <?php endif; ?>

                <?php if ($is_new): ?>
                    <hr class="my-4">
                    <?php $password_errors = $errors; ?>
                    <?php require __DIR__ . '/includes/admin_password_fields.php'; ?>
                <?php endif; ?>
            </div>
            <div class="admin-panel-footer">
                <a class="btn btn-light" href="<?= e(url('admin/admin_users.php')) ?>">Cancel</a>
                <button class="btn btn-chimbo" type="submit" name="form_action" value="save"><?= $is_new ? 'Create account' : 'Save changes' ?></button>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <section class="admin-panel mb-3">
            <div class="admin-panel-header"><h2 class="admin-panel-title">What each role can open</h2></div>
            <div class="admin-panel-body">
                <!-- Built from the menu and Admin::ROLE_PERMISSIONS, so it always matches what each role really sees -->
                <dl class="admin-details mb-0">
                    <?php foreach (Admin::ROLE_NAMES as $role => $role_name): ?>
                        <?php $role_pages = array_merge(...array_values(adminVisibleMenu(['admin_role' => $role]))); ?>
                        <dt><?= e($role_name) ?></dt>
                        <dd><?= e(implode(', ', array_column($role_pages, 0))) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </section>

        <?php if (!$is_new): ?>
            <form class="admin-panel" method="post" novalidate autocomplete="off">
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title">Reset password</h2></div>
                <div class="admin-panel-body">
                    <dl class="admin-details mb-3">
                        <dt>Status</dt>
                        <dd>
                            <?= adminStatusBadge($edited_admin['admin_status']) ?>
                            <?php if ($edited_admin['admin_is_locked']): ?>
                                <?= adminStatusBadge('locked') ?> <span class="small text-muted">after wrong passwords</span>
                            <?php endif; ?>
                        </dd>
                        <dt>Last login</dt>
                        <dd><?= e(adminDateTime($edited_admin['admin_last_login_at'])) ?></dd>
                    </dl>
                    <?php $password_errors = $password_failed ? $errors : []; ?>
                    <?php require __DIR__ . '/includes/admin_password_fields.php'; ?>
                    <div class="form-text">Setting a new password also unlocks a locked account.</div>
                </div>
                <div class="admin-panel-footer">
                    <button class="btn btn-outline-danger" type="submit" name="form_action" value="reset_password"
                            data-confirm="Set a new password for this account?">Set new password</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
