<?php

/**
 * The bell (admin/includes/header.php + admin.js):
 *   ?part=panel → the modal's HTML ("Waiting for you" + the 10 latest notifications)
 *   ?part=count → {"unread_count": 3, "has_waiting_work": true} for the badge and the orange dot, checked every 15 seconds. This check doesn't count as the staff
 *                 member doing something, so the 30-minute idle logout still works.
 */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

$part          = ($_GET['part'] ?? '') === 'panel' ? 'panel' : 'count';
$current_admin = AdminSession::requireAjaxLogin('dashboard.view', is_activity: $part === 'panel');
$notification_model = new AdminNotification(Database::instance());

if ($part === 'count') {
    adminSendJson([
        'unread_count'     => $notification_model->countUnread($current_admin),
        'has_waiting_work' => adminAttentionItems($current_admin) !== [],
    ]);
}

$attention_items = adminAttentionItems($current_admin);
$notifications   = $notification_model->getLatest($current_admin, 10);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
require dirname(__DIR__) . '/includes/notification_panel.php';
