<?php

/**
 * Records who did what, for accountability (admin actions, payments, status changes).
 *
 * How to use it:
 *   $audit_log = new AuditLog(Database::instance());
 *   $audit_log->record('admin', $admin_id, 'product.updated', 'product', $product_id, $old_row, $new_row);
 *   $page = $audit_log->search($_GET);   // the "Audit log" page: ['items', 'total', 'page', 'per_page']
 */
class AuditLog
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];
    private const DEFAULT_PER_PAGE = 50;
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

    /**
     * One page of the log, newest first.
     * Filters (all optional): admin_id, actor_type (admin / customer / system), action (exact, e.g. "product.updated"),
     * entity_type (e.g. "order") + entity_id, date_from / date_to (Y-m-d, East Africa time), page, per_page (25/50/100).
     * Items have old_values / new_values already decoded to arrays, and admin_full_name for staff actions.
     */
    public function search(array $filters): array
    {
        $data = Validator::validate($filters, [
            'admin_id'    => 'nullable|int|min:1',
            'actor_type'  => 'nullable|in:admin,customer,system',
            'action'      => 'nullable|string|max:100',
            'entity_type' => 'nullable|string|max:50',
            'entity_id'   => 'nullable|int|min:1',
            'date_from'   => 'nullable|date',
            'date_to'     => 'nullable|date',
            'page'        => 'nullable|int|min:1',
            'per_page'    => 'nullable|int|in:' . implode(',', self::PER_PAGE_OPTIONS),
        ]);
        $per_page = $data['per_page'] ?? self::DEFAULT_PER_PAGE;

        [$where_sql, $params] = $this->buildSearchConditions($data);

        $total     = (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs a WHERE {$where_sql}", $params);
        $last_page = max(1, (int) ceil($total / $per_page));
        $page      = min($data['page'] ?? 1, $last_page);

        $rows = $this->db->fetchAll(
            "SELECT a.audit_log_id, a.audit_log_actor_type, a.audit_log_actor_id, a.audit_log_action,
                    a.audit_log_entity_type, a.audit_log_entity_id, a.audit_log_old_values, a.audit_log_new_values,
                    a.audit_log_ip_address, a.created_at, admins.admin_full_name
             FROM audit_logs a
             LEFT JOIN admins ON a.audit_log_actor_type = 'admin' AND admins.admin_id = a.audit_log_actor_id
             WHERE {$where_sql}
             ORDER BY a.audit_log_id DESC
             LIMIT {$per_page} OFFSET " . (($page - 1) * $per_page),
            $params
        );

        foreach ($rows as &$row) {
            $row['audit_log_old_values'] = $row['audit_log_old_values'] === null ? null : json_decode($row['audit_log_old_values'], true);
            $row['audit_log_new_values'] = $row['audit_log_new_values'] === null ? null : json_decode($row['audit_log_new_values'], true);
        }
        unset($row);

        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page];
    }

    /** Every action name that has been logged, for the filter dropdown (e.g. "order.status_changed"). */
    public function getActions(): array
    {
        return array_column($this->db->fetchAll('SELECT DISTINCT audit_log_action FROM audit_logs ORDER BY audit_log_action'), 'audit_log_action');
    }

    /** Every entity type that has been logged, for the filter dropdown (e.g. "product"). */
    public function getEntityTypes(): array
    {
        return array_column($this->db->fetchAll('SELECT DISTINCT audit_log_entity_type FROM audit_logs ORDER BY audit_log_entity_type'), 'audit_log_entity_type');
    }

    private function buildSearchConditions(array $data): array
    {
        $conditions = ['1 = 1'];
        $params     = [];

        if (isset($data['admin_id'])) {
            $conditions[]       = "a.audit_log_actor_type = 'admin' AND a.audit_log_actor_id = :admin_id";
            $params['admin_id'] = $data['admin_id'];
        }
        foreach (['actor_type' => 'audit_log_actor_type', 'action' => 'audit_log_action', 'entity_type' => 'audit_log_entity_type', 'entity_id' => 'audit_log_entity_id'] as $filter => $column) {
            if (isset($data[$filter])) {
                $conditions[]    = "a.{$column} = :{$filter}";
                $params[$filter] = $data[$filter];
            }
        }
        // The dates are East Africa days; the log is saved in UTC
        if (isset($data['date_from'])) {
            $conditions[]        = 'a.created_at >= :date_from';
            $params['date_from'] = localTimeToUtc($data['date_from'] . ' 00:00:00');
        }
        if (isset($data['date_to'])) {
            $conditions[]      = 'a.created_at < :date_to';
            $params['date_to'] = localTimeToUtc($data['date_to'] . ' 00:00:00 +1 day');
        }

        return [implode(' AND ', $conditions), $params];
    }
}
