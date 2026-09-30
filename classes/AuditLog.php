<?php

/**
 * Records who did what, for accountability (admin actions, payments, status changes).
 *
 * How to use it:
 *   $audit_log = new AuditLog(Database::instance());
 *   $audit_log->record('admin', $admin_id, 'product.updated', 'product', $product_id, $old_row, $new_row);
 */
class AuditLog
{
    public function __construct(private Database $db)
    {
    }

    public function record(
        string $actor_type,          // 'admin', 'customer' or 'system'
        ?int $actor_id,
        string $action,              // e.g. 'admin.login', 'product.updated'
        string $entity_type,         // what was changed, e.g. 'admin', 'product', 'order'
        ?int $entity_id = null,
        ?array $old_values = null,   // the row before the change
        ?array $new_values = null    // the row after the change
    ): void {
        $this->db->insert(
            'INSERT INTO audit_logs (
                audit_log_actor_type, audit_log_actor_id, audit_log_action, audit_log_entity_type,
                audit_log_entity_id, audit_log_old_values, audit_log_new_values, audit_log_ip_address
             ) VALUES (
                :actor_type, :actor_id, :action, :entity_type,
                :entity_id, :old_values, :new_values, :ip_address
             )',
            [
                'actor_type'  => $actor_type,
                'actor_id'    => $actor_id,
                'action'      => $action,
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
                'old_values'  => $old_values === null ? null : json_encode($old_values, JSON_UNESCAPED_UNICODE),
                'new_values'  => $new_values === null ? null : json_encode($new_values, JSON_UNESCAPED_UNICODE),
                'ip_address'  => clientIp() ?: null,
            ]
        );
    }
}
