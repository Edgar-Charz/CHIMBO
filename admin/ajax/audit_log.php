<?php

/** Rows for the Audit log table (server-side DataTables, see admin/audit_log.php). */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

AdminSession::requireAjaxLogin('audit.view');

try {
    $pagination = (new AuditLog(Database::instance()))->search(adminDataTablesInput($_GET, 'search'));
} catch (ApiException $e) {
    adminDataTablesError($e);
}

/** Who made the change: the staff member's name, a link to the customer, or "System". */
$actor = function (array $entry): string {
    $actor_id = $entry['audit_log_actor_id'];
    return match ($entry['audit_log_actor_type']) {
        'admin'    => e($entry['admin_full_name'] ?? "Staff #{$actor_id}"),
        'customer' => '<a href="' . e(url('admin/customer_details.php?id=' . $actor_id)) . '">Customer #' . e($actor_id) . '</a>',
        default    => '<span class="text-muted">System</span>',
    };
};

adminDataTablesJson($pagination, fn (array $entry): array => [
    'when'    => e(adminDateTime($entry['created_at'])),
    'who'     => $actor($entry),
    'action'  => '<code class="audit-action">' . e($entry['audit_log_action']) . '</code>',
    'record'  => adminAuditEntity($entry['audit_log_entity_type'], $entry['audit_log_entity_id'] === null ? null : (int) $entry['audit_log_entity_id']),
    'changes' => adminAuditChanges($entry['audit_log_old_values'], $entry['audit_log_new_values']),
    'ip'      => e($entry['audit_log_ip_address'] ?? '—'),
], '');   // plain rows: the links are inside the cells
