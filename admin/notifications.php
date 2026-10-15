<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// Every staff notification this staff member may see, newest first, a page at a time; "Unread only" filter;
// "Mark all as read". Each one opens its page (notification_open.php).
$current_admin      = AdminSession::requireLogin('dashboard.view');
$notification_model = new AdminNotification(Database::instance());

$form_error = adminHandleForm(function (string $form_action) use ($notification_model, $current_admin): void {
    if ($form_action === 'mark_all_read') {
        $notification_model->markAllRead($current_admin);
        Session::flash('success', 'All notifications are marked as read.');
    }
    $return_to = AdminNotification::safeLink((string) ($_POST['return_to'] ?? 'notifications.php'));
    redirect(url('admin/' . $return_to));
});

$only_unread   = ($_GET['only_unread'] ?? '') === '1';
$page_number   = max(1, (int) ($_GET['page'] ?? 1));
$result        = $notification_model->search($current_admin, ['only_unread' => $only_unread ? '1' : null, 'page' => $page_number, 'per_page' => 25]);
$notifications = $result['items'];
$last_page     = max(1, (int) ceil($result['total'] / $result['per_page']));
$page_link     = fn (int $number): string => url('admin/notifications.php?' . http_build_query(['only_unread' => $only_unread ? '1' : null, 'page' => $number]));

$page_title  = 'Notifications';
$active_menu = 'notifications';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions">
    <div class="btn-group" role="group" aria-label="Show">
        <a class="btn <?= $only_unread ? 'btn-outline-secondary' : 'btn-chimbo' ?>" href="<?= e(url('admin/notifications.php')) ?>">All</a>
        <a class="btn <?= $only_unread ? 'btn-chimbo' : 'btn-outline-secondary' ?>" href="<?= e(url('admin/notifications.php?only_unread=1')) ?>">Unread only</a>
    </div>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="notifications.php">
        <button class="btn btn-outline-secondary" type="submit" name="form_action" value="mark_all_read"><i class="bi bi-check2-all"></i> Mark all as read</button>
    </form>
</div>

<div class="admin-panel">
    <?php if ($notifications === []): ?>
        <div class="admin-panel-body text-muted"><?= $only_unread ? 'Nothing unread.' : 'No notifications yet.' ?></div>
    <?php else: ?>
        <?php require __DIR__ . '/includes/notification_list.php'; ?>
    <?php endif; ?>
</div>

<?php if ($last_page > 1): ?>
    <nav class="d-flex align-items-center justify-content-center gap-3 mt-3" aria-label="Pages">
        <?php if ($page_number > 1): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e($page_link($page_number - 1)) ?>"><i class="bi bi-arrow-left"></i> Newer</a><?php endif; ?>
        <span class="small text-muted">Page <?= e($result['page']) ?> of <?= e($last_page) ?></span>
        <?php if ($page_number < $last_page): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e($page_link($page_number + 1)) ?>">Older <i class="bi bi-arrow-right"></i></a><?php endif; ?>
    </nav>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
