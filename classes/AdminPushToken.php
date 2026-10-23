<?php

/** Registers staff browser tokens and delivers staff notifications through FCM. */
class AdminPushToken
{
    public function __construct(private Database $db)
    {
    }

    public function register(int $admin_id, string $device_token): void
    {
        $token_hash = hash('sha256', $device_token);
        $this->db->execute(
            'INSERT INTO admin_push_tokens (admin_id, admin_push_token_hash, admin_push_token, last_seen_at)
             VALUES (:admin_id, :token_hash, :device_token, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id), admin_push_token = VALUES(admin_push_token),
                                     last_seen_at = UTC_TIMESTAMP()',
            ['admin_id' => $admin_id, 'token_hash' => $token_hash, 'device_token' => $device_token]
        );
    }

    public function remove(int $admin_id, string $device_token): void
    {
        $this->db->execute(
            'DELETE FROM admin_push_tokens WHERE admin_id = :admin_id AND admin_push_token_hash = :token_hash',
            ['admin_id' => $admin_id, 'token_hash' => hash('sha256', $device_token)]
        );
    }

    public function removeById(int $token_id): void
    {
        $this->db->execute(
            'DELETE FROM admin_push_tokens WHERE admin_push_token_id = :token_id',
            ['token_id' => $token_id]
        );
    }

    public function sendNotification(int $notification_id, FcmPushSender $sender): void
    {
        try {
            $notification = $this->db->fetchOne(
                'SELECT admin_notification_type, admin_notification_title, admin_notification_body,
                        admin_notification_link, admin_notification_permission
                 FROM admin_notifications WHERE admin_notification_id = :notification_id',
                ['notification_id' => $notification_id]
            );
            if ($notification === null) {
                return;
            }
            $tokens = $this->db->fetchAll(
                'SELECT t.admin_push_token_id, t.admin_id, t.admin_push_token, a.admin_role
                 FROM admin_push_tokens t
                 INNER JOIN admins a ON a.admin_id = t.admin_id
                 WHERE a.admin_status = \'active\'',
            );
            $link = AdminNotification::safeLink($notification['admin_notification_link']);
            $data = ['url' => url('admin/' . $link), 'notification_type' => $notification['admin_notification_type']];
            foreach ($tokens as $token) {
                if (!Admin::can(['admin_role' => $token['admin_role']], $notification['admin_notification_permission'])) {
                    continue;
                }
                try {
                    if (!$sender->send($token['admin_push_token'], $notification['admin_notification_title'],
                        $notification['admin_notification_body'], $data)) {
                        $this->removeById((int) $token['admin_push_token_id']);
                    }
                } catch (Throwable $exception) {
                    Logger::warning('Admin browser push delivery failed.', [
                        'admin_notification_id' => $notification_id,
                        'admin_push_token_id' => (int) $token['admin_push_token_id'],
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $exception) {
            Logger::warning('Admin browser push lookup failed.', [
                'admin_notification_id' => $notification_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}