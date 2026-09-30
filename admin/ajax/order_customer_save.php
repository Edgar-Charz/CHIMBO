<?php

/**
 * Saves a customer typed into the "Add order" form (step 1) with their delivery address, so the next steps
 * use their account. Answers with the customer and the address id, or the error and field messages.
 */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireAjaxLogin('orders.manage');
Csrf::verifyOrFail($_POST['csrf_token'] ?? null);

try {
    $result = (new OrderManager(Database::instance()))->createManualCustomer($_POST, (int) $current_admin['admin_id']);
    adminSendJson($result);
} catch (ApiException $e) {
    http_response_code($e->status());
    adminSendJson(['error' => $e->getMessage(), 'fields' => $e->fields()]);
}
