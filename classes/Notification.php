<?php

/**
 * In-app notifications (the bell): order updates, payments, promotions. Each customer sees only their own.
 *
 * How to use it:
 *   (new Notification($db))->notifyUser($user_id, 'order_status', 'Oda imetumwa', 'Oda #CHB123456 iko njiani.', ['order_id' => 12]);
 */
class Notification
{
    private const PER_PAGE = 20;

    public function __construct(private Database $db)
    {
    }

    /** Adds a notification for a customer. $data tells the app what to open (e.g. the order). */
    public function notifyUser(
        int $user_id,
        string $type,
        string $title,
        string $body,
        array $data = [],
        array $translations = []
    ): void
    {
        $data = $this->withTranslations($data, $translations);
        $notification_id = $this->insertNotification($user_id, $type, $title, $body, $data);

        $sender = new FcmPushSender();
        if ($sender->isConfigured()) {
            $queue = new NotificationPushQueue($this->db);
            $queue_id = $queue->enqueue($notification_id, $user_id);
            $this->db->afterCommit(static function () use ($queue, $queue_id, $sender): void {
                $queue->processOne($queue_id, $sender);
            });
        }
    }

    /** Saves a broadcast for every recipient together so the request never sends pushes one by one. */
    public function notifyUsers(
        array $user_ids,
        string $type,
        string $title,
        string $body,
        array $data = [],
        array $translations = []
    ): int
    {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static fn (int $user_id): bool => $user_id > 0)));
        if ($user_ids === []) {
            return 0;
        }

        $data = $this->withTranslations($data, $translations);
        $queue = (new FcmPushSender())->isConfigured() ? new NotificationPushQueue($this->db) : null;
        return $this->db->transaction(function () use ($user_ids, $type, $title, $body, $data, $queue): int {
            foreach ($user_ids as $user_id) {
                $notification_id = $this->insertNotification($user_id, $type, $title, $body, $data);
                $queue?->enqueue($notification_id, $user_id);
            }
            return count($user_ids);
        });
    }

    /** Counts customer accounts that can receive an admin broadcast. */
    public function countActiveUsers(): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE user_status = 'active'");
    }

    /** Sends an in-app broadcast to all active customer accounts; push delivery stays in the batch queue. */
    public function notifyActiveUsers(string $title, string $body, array $translations = []): int
    {
        $users = $this->db->fetchAll("SELECT user_id FROM users WHERE user_status = 'active' ORDER BY user_id ASC");
        $user_ids = array_map(static fn (array $user): int => (int) $user['user_id'], $users);
        return $this->notifyUsers($user_ids, 'system', $title, $body, [], $translations);
    }

    private function withTranslations(array $data, array $translations): array
    {
        if ($translations !== []) {
            $data['translations'] = $translations;
        }
        return $data;
    }

    private function insertNotification(int $user_id, string $type, string $title, string $body, array $data): int
    {
        return $this->db->insert(
            'INSERT INTO notifications (user_id, notification_type, notification_title, notification_body, notification_data)
             VALUES (:user_id, :notification_type, :notification_title, :notification_body, :notification_data)',
            [
                'user_id'            => $user_id,
                'notification_type'  => $type,
                'notification_title' => $title,
                'notification_body'  => $body,
                'notification_data'  => $data ? json_encode($data) : null,
            ]
        );
    }

    /** One page of the customer's notifications, newest first. */
    public function getNotifications(int $user_id, array $input): array
    {
        $data   = Validator::validate($input, ['page' => 'nullable|int|min:1']);
        $page   = $data['page'] ?? 1;
        $offset = ($page - 1) * self::PER_PAGE;

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id', ['user_id' => $user_id]);
        $rows  = $this->db->fetchAll(
            'SELECT notification_id, notification_type, notification_title, notification_body, notification_data,
                    notification_read_at, created_at
             FROM notifications
             WHERE user_id = :user_id
             ORDER BY notification_id DESC
             LIMIT ' . self::PER_PAGE . " OFFSET {$offset}",
            ['user_id' => $user_id]
        );

        $items = array_map(fn (array $row) => [
            'notification_id'    => (int) $row['notification_id'],
            'notification_type'  => $row['notification_type'],
            'notification_title' => $row['notification_title'],
            'notification_body'  => $row['notification_body'],
            'notification_data'  => $row['notification_data'] ? json_decode($row['notification_data'], true) : null,
            'is_read'            => $row['notification_read_at'] !== null,
            'created_at'         => isoDate($row['created_at']),
        ], $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE];
    }

    /** The number on the bell. */
    public function getUnreadCount(int $user_id): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND notification_read_at IS NULL',
            ['user_id' => $user_id]
        );
    }

    public function markAsRead(int $user_id, int $notification_id): void
    {
        $this->db->execute(
            'UPDATE notifications SET notification_read_at = UTC_TIMESTAMP()
             WHERE notification_id = :notification_id AND user_id = :user_id AND notification_read_at IS NULL',
            ['notification_id' => $notification_id, 'user_id' => $user_id]
        );
    }

    public function markAllAsRead(int $user_id): void
    {
        $this->db->execute(
            'UPDATE notifications SET notification_read_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND notification_read_at IS NULL',
            ['user_id' => $user_id]
        );
    }
}
