<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('admins.manage');
$admins        = (new AdminUser(Database::instance()))->listAdmins();

$page_title  = 'Admin users';
$active_menu = 'admin_users';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions">
    <p class="text-muted mb-0">Staff accounts for this dashboard. The role decides which pages each person can open.</p>
    <a class="btn btn-chimbo" href="<?= e(url('admin/admin_user_edit.php')) ?>"><i class="bi bi-plus-lg"></i> Add staff account</a>
</div>

<div class="admin-panel admin-table-panel">
    <table class="table admin-table w-100" data-datatable data-title="All staff accounts" data-icon="bi-person-badge"
           data-order='[[0,"asc"]]' data-search-placeholder="Search name or email">
        <thead>
            <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last login</th>
                <th>Created</th>
                <th class="text-end" data-orderable="false">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($admins as $admin): ?>
                <tr class="row-link">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="initials-avatar"><?= e(adminInitials($admin['admin_full_name'])) ?></span>
                            <div>
                                <a class="stretched-link fw-semibold" href="<?= e(url('admin/admin_user_edit.php?id=' . $admin['admin_id'])) ?>">
                                    <?= e($admin['admin_full_name']) ?>
                                </a>
                                <?php if ((int) $admin['admin_id'] === (int) $current_admin['admin_id']): ?>
                                    <span class="small text-muted">(you)</span>
                                <?php endif; ?>
                                <div class="small text-muted"><?= e($admin['admin_email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?= e(Admin::ROLE_NAMES[$admin['admin_role']] ?? $admin['admin_role']) ?></td>
                    <td class="text-nowrap">
                        <?= adminStatusBadge($admin['admin_status']) ?>
                        <?php if ($admin['admin_is_locked']): ?>
                            <?= adminStatusBadge('locked') ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap" data-order="<?= e($admin['admin_last_login_at'] ?? '') ?>"><?= e(adminDateTime($admin['admin_last_login_at'])) ?></td>
                    <td class="text-nowrap" data-order="<?= e($admin['created_at']) ?>"><?= e(adminDate($admin['created_at'])) ?></td>
                    <td class="text-end">
                        <?= adminRowActions(adminActionLink('bi-pencil', 'Edit or reset password', url('admin/admin_user_edit.php?id=' . $admin['admin_id']))) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
