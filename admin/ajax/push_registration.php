<?php

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireAjaxLogin('dashboard.view', is_activity: false);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    adminSendJson(['error' => 'Method not allowed.']);
}
Csrf::verifyOrFail($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
$input = json_decode((string) file_get_contents('php://input'), true);
$device_token = is_array($input) ? trim((string) ($input['device_token'] ?? '')) : '';
if (strlen($device_token) < 20 || strlen($device_token) > 4096) {
    http_response_code(422);
    adminSendJson(['error' => 'The browser notification token is invalid.']);
}
$push_tokens = new AdminPushToken(Database::instance());
if (($input['action'] ?? 'register') === 'remove') {
    http_response_code(409);
    adminSendJson(['error' => 'Push registrations remain active after logout.']);
}
$push_tokens->register((int) $current_admin['admin_id'], $device_token);
adminSendJson(['success' => true]);