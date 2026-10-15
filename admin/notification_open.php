<?php
require __DIR__ . '/includes/admin_bootstrap.php';

// Opening a notification (from the bell or the Notifications page): marks it read for this staff member and goes to
// its page. Only pages inside admin/ can be opened (AdminNotification::safeLink()).
$current_admin = AdminSession::requireLogin('dashboard.view');

$page = adminLoadOrRedirect(
    fn () => ['page' => (new AdminNotification(Database::instance()))->open($current_admin, (int) ($_GET['id'] ?? 0))],
    'notifications.php'
)['page'];
redirect(url('admin/' . $page));
