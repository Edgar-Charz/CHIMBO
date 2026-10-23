<?php

/** Tracks customer notification delivery attempts through FCM. */
class NotificationPushQueue
{
    private const BATCH_SIZE = 50;
    private const LOCK_MINUTES = 5;

    public function __construct(private Database $db)
    {
    }

    /** Keeps push delivery in the same transaction as the in-app notification. */
    public function enqueue(int $notification_id, int $user_id): int
    {
        return $this->db->insert(
            'INSERT INTO notification_push_queue (notification_id, user_id)
             VALUES (:notification_id, :user_id)',
            ['notification_id' => $notification_id, 'user_id' => $user_id]
        );
    }

    /** Sends available jobs; intended for a scheduled CLI worker, never a customer request. */
    public function process(FcmPushSender $sender): int
    {
        if (!$sender->isConfigured()) {
            return 0;
        }

        $jobs = $this->db->fetchAll(
            'SELECT notification_push_id FROM notification_push_queue
             WHERE (push_status = \'pending\' AND push_next_attempt_at <= UTC_TIMESTAMP())
                OR (push_status = \'processing\' AND push_locked_until < UTC_TIMESTAMP())
             ORDER BY notification_push_id ASC LIMIT ' . self::BATCH_SIZE
        );
        $processed = 0;
        foreach ($jobs as $job) {
            $queue_id = (int) $job['notification_push_id'];
            if (!$this->claim($queue_id)) {
                continue;
            }
            $this->sendJob($queue_id, $sender);
            $processed++;
        }

        return $processed;
    }

    /** Attempts one newly queued push immediately after its notification has committed. */
    public function processOne(int $queue_id, FcmPushSender $sender): void
    {
        if (!$sender->isConfigured() || !$this->claim($queue_id)) {
            return;
        }

        $this->sendJob($queue_id, $sender);
    }

    private function claim(int $queue_id): bool
    {
        return $this->db->execute(
            'UPDATE notification_push_queue
             SET push_status = \'processing\', push_attempts = push_attempts + 1,
                 push_locked_until = UTC_TIMESTAMP() + INTERVAL ' . self::LOCK_MINUTES . ' MINUTE
             WHERE notification_push_id = :queue_id
               AND ((push_status = \'pending\' AND push_next_attempt_at <= UTC_TIMESTAMP())
                    OR (push_status = \'processing\' AND push_locked_until < UTC_TIMESTAMP()))',
            ['queue_id' => $queue_id]
        ) === 1;
    }

    private function sendJob(int $queue_id, FcmPushSender $sender): void
    {
        try {
            $job = $this->notification($queue_id);
            if ($job === null) {
                $this->markSent($queue_id);
                return;
            }

            $devices = (new DeviceToken($this->db))->forUser((int) $job['user_id']);
            foreach ($devices as $device) {
                $translations = $this->translations($job['notification_data'], $device['device_language']);
                if (!$sender->send(
                    $device['device_token'],
                    $translations['title'] ?? $job['notification_title'],
                    $translations['body'] ?? $job['notification_body'],
                    $this->pushData($job)
                )) {
                    (new DeviceToken($this->db))->removeById((int) $device['device_token_id']);
                }
            }
            $this->markSent($queue_id);
        } catch (Throwable $exception) {
            $this->markFailed($queue_id, $exception);
        }
    }

    private function notification(int $queue_id): ?array
    {
        return $this->db->fetchOne(
            'SELECT q.user_id, n.notification_id, n.notification_type, n.notification_title,
                    n.notification_body, n.notification_data
             FROM notification_push_queue q
             INNER JOIN notifications n ON n.notification_id = q.notification_id
             WHERE q.notification_push_id = :queue_id',
            ['queue_id' => $queue_id]
        );
    }

    private function pushData(array $job): array
    {
        $data = is_string($job['notification_data']) ? json_decode($job['notification_data'], true) : [];
        $data = is_array($data) ? $data : [];
        $translations = $data['translations'] ?? [];
        unset($data['translations']);
        foreach (['en', 'sw'] as $language_code) {
            $copy = $translations[$language_code] ?? [];
            if (isset($copy['title'], $copy['body'])) {
                $data['notification_title_' . $language_code] = $copy['title'];
                $data['notification_body_' . $language_code] = $copy['body'];
            }
        }
        $data['notification_id'] = (int) $job['notification_id'];
        $data['notification_type'] = $job['notification_type'];
        $scalar_data = array_filter($data, 'is_scalar');
        return array_map('strval', $scalar_data);
    }

    private function translations(?string $notification_data, string $language_code): array
    {
        $data = $notification_data === null ? [] : json_decode($notification_data, true);
        $translations = is_array($data) ? ($data['translations'] ?? []) : [];
        return is_array($translations) ? ($translations[$language_code] ?? []) : [];
    }

    private function markSent(int $queue_id): void
    {
        $this->db->execute(
            'UPDATE notification_push_queue
             SET push_status = \'sent\', push_locked_until = NULL, push_last_error = NULL
             WHERE notification_push_id = :queue_id',
            ['queue_id' => $queue_id]
        );
    }

    private function markFailed(int $queue_id, Throwable $exception): void
    {
        Logger::warning('Customer push delivery failed.', [
            'notification_push_id' => $queue_id,
            'error' => $exception->getMessage(),
        ]);
        $this->db->execute(
            'UPDATE notification_push_queue
             SET push_status = \'failed\', push_locked_until = NULL, push_last_error = :last_error
             WHERE notification_push_id = :queue_id',
            ['queue_id' => $queue_id, 'last_error' => mb_substr($exception->getMessage(), 0, 120)]
        );
    }
}
