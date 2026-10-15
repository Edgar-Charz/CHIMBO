<?php

/**
 * The staff bell: notifications about things that happened (new order, payment to check, low stock …).
 * Each one is for the staff whose role has its permission; read / unread is kept per staff member.
 *
 * How to use it:
 *   (new AdminNotification($db))->notify('order.placed', 'New order CHB123456', 'Joyce · 45,000 TZS', 'order_details.php?id=12');
 *   $latest = $admin_notification_model->getLatest($current_admin, 10);
 *   $admin_notification_model->countUnread($current_admin);
 *
 * Each type has its permission (TYPES), so callers can't send a notification to the wrong staff.
 */
class AdminNotification
{
    // type => [permission of the staff who see it, Bootstrap icon]
    public const TYPES = [
        'order.placed'        => ['orders.view',     'bi-bag-check'],
        'order.cancelled'     => ['orders.view',     'bi-x-circle'],
        'orders.expired'      => ['orders.view',     'bi-hourglass-bottom'],
        'delivery.to_confirm' => ['orders.manage',   'bi-truck'],
        'payment.submitted'   => ['payments.manage', 'bi-cash-coin'],
        'refund.due'          => ['payments.manage', 'bi-arrow-counterclockwise'],
        'stock.low'           => ['inventory.manage', 'bi-exclamation-triangle'],
        'customer.registered' => ['customers.view',  'bi-person-plus'],
    ];
    public const PER_PAGE_OPTIONS = [25, 50, 100];
    private const DEFAULT_PER_PAGE = 25;

    public function __construct(private Database $db)
    {
    }

    /** Saves a notification for the staff who handle this type. $link = the admin page it opens (relative, no "admin/"). */
    public function notify(string $type, string $title, string $body, ?string $link): void
    {
        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException("Unknown staff notification type: {$type}");
        }
        $this->db->insert(
            'INSERT INTO admin_notifications (admin_notification_type, admin_notification_title, admin_notification_body,
                                              admin_notification_link, admin_notification_permission)
             VALUES (:type, :title, :body, :link, :permission)',
            [
                'type'       => $type,
                'title'      => mb_substr($title, 0, 150),
                'body'       => mb_substr($body, 0, 500),
                'link'       => $link,
                'permission' => self::TYPES[$type][0],
            ]
        );
    }

    /** The newest notifications this staff member may see, with is_read. */
    public function getLatest(array $admin, int $limit = 10): array
    {
        [$where_sql, $params] = $this->visibleTo($admin);
        $rows = $this->db->fetchAll(
            "SELECT n.*, r.read_at IS NOT NULL AS is_read
             FROM admin_notifications n
             LEFT JOIN admin_notification_reads r ON r.admin_notification_id = n.admin_notification_id AND r.admin_id = :reader_id
             WHERE {$where_sql}
             ORDER BY n.admin_notification_id DESC
             LIMIT " . max(1, $limit),
            $params + ['reader_id' => $admin['admin_id']]
        );
        return array_map(fn (array $row): array => $this->format($row), $rows);
    }

    /** The Notifications page: filters only_unread (1), page, per_page (25/50/100) → ['items', 'total', 'page', 'per_page']. */
    public function search(array $admin, array $filters): array
    {
        $data = Validator::validate($filters, [
            'only_unread' => 'nullable|bool',
            'page'        => 'nullable|int|min:1',
            'per_page'    => 'nullable|int|in:' . implode(',', self::PER_PAGE_OPTIONS),
        ]);
        $per_page = $data['per_page'] ?? self::DEFAULT_PER_PAGE;

        [$where_sql, $params] = $this->visibleTo($admin);
        if (!empty($data['only_unread'])) {
            $where_sql .= ' AND r.read_at IS NULL';
        }
        $from_sql = "FROM admin_notifications n
                     LEFT JOIN admin_notification_reads r ON r.admin_notification_id = n.admin_notification_id AND r.admin_id = :reader_id
                     WHERE {$where_sql}";
        $params += ['reader_id' => $admin['admin_id']];

        $total     = (int) $this->db->fetchValue("SELECT COUNT(*) {$from_sql}", $params);
        $last_page = max(1, (int) ceil($total / $per_page));
        $page      = min($data['page'] ?? 1, $last_page);
        $rows = $this->db->fetchAll(
            "SELECT n.*, r.read_at IS NOT NULL AS is_read {$from_sql}
             ORDER BY n.admin_notification_id DESC
             LIMIT {$per_page} OFFSET " . (($page - 1) * $per_page),
            $params
        );

        return ['items' => array_map(fn (array $row): array => $this->format($row), $rows), 'total' => $total, 'page' => $page, 'per_page' => $per_page];
    }

    /** The number on the bell. */
    public function countUnread(array $admin): int
    {
        [$where_sql, $params] = $this->visibleTo($admin);
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM admin_notifications n
             LEFT JOIN admin_notification_reads r ON r.admin_notification_id = n.admin_notification_id AND r.admin_id = :reader_id
             WHERE {$where_sql} AND r.read_at IS NULL",
            $params + ['reader_id' => $admin['admin_id']]
        );
    }

    /**
     * Opening a notification: marks it read for this staff member and returns the admin page to go to
     * ("index.php" when it has none). 404 when it doesn't exist or isn't for them.
     */
    public function open(array $admin, int $admin_notification_id): string
    {
        [$where_sql, $params] = $this->visibleTo($admin);
        $link = $this->db->fetchOne(
            "SELECT n.admin_notification_link FROM admin_notifications n WHERE n.admin_notification_id = :id AND {$where_sql}",
            $params + ['id' => $admin_notification_id]
        );
        if ($link === null) {
            throw ApiException::notFound('Notification not found.');
        }
        $this->db->execute(
            'INSERT IGNORE INTO admin_notification_reads (admin_id, admin_notification_id) VALUES (:admin_id, :id)',
            ['admin_id' => $admin['admin_id'], 'id' => $admin_notification_id]
        );
        return self::safeLink($link['admin_notification_link']);
    }

    /** "Mark all as read": every notification this staff member may see. */
    public function markAllRead(array $admin): void
    {
        [$where_sql, $params] = $this->visibleTo($admin);
        $this->db->execute(
            "INSERT IGNORE INTO admin_notification_reads (admin_id, admin_notification_id)
             SELECT :reader_id, n.admin_notification_id FROM admin_notifications n WHERE {$where_sql}",
            $params + ['reader_id' => $admin['admin_id']]
        );
    }

    /**
     * Only an admin page inside admin/ may be opened from a notification (never another site):
     * "order_details.php?id=12" is kept; anything with a scheme, "//", ".." or a leading "/" becomes the dashboard.
     */
    public static function safeLink(?string $link): string
    {
        $is_admin_page = $link !== null && preg_match('#^[a-z0-9_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $link) === 1;
        return $is_admin_page ? $link : 'index.php';
    }

    /** SQL + values limiting notifications to the permissions of this staff member's role. */
    private function visibleTo(array $admin): array
    {
        $permissions = Admin::ROLE_PERMISSIONS[$admin['admin_role']] ?? [];
        if (in_array('*', $permissions, true)) {
            return ['1 = 1', []];
        }
        if ($permissions === []) {
            return ['1 = 0', []];
        }
        $placeholders = [];
        $params       = [];
        foreach (array_values($permissions) as $index => $permission) {
            $placeholders[]               = ":permission_{$index}";
            $params["permission_{$index}"] = $permission;
        }
        return ['n.admin_notification_permission IN (' . implode(', ', $placeholders) . ')', $params];
    }

    private function format(array $row): array
    {
        return [
            'admin_notification_id' => (int) $row['admin_notification_id'],
            'type'                  => $row['admin_notification_type'],
            'icon'                  => self::TYPES[$row['admin_notification_type']][1] ?? 'bi-bell',
            'title'                 => $row['admin_notification_title'],
            'body'                  => $row['admin_notification_body'],
            'is_read'               => (bool) $row['is_read'],
            'created_at'            => $row['created_at'],   // UTC
        ];
    }
}
