<?php

/** Registers the current customer's mobile devices for push delivery. */
class DeviceToken
{
    private const RULES = [
        'device_token'    => 'required|string|min:20|max:4096',
        'device_platform' => 'required|string|in:android,ios',
        'device_language' => 'nullable|in:sw,en',
    ];

    public function __construct(private Database $db)
    {
    }

    /** Adds or refreshes a registration; a token belongs to only one account at a time. */
    public function register(int $user_id, string $auth_token, array $input): void
    {
        $data = Validator::validate($input, self::RULES);
        $token_hash = hash('sha256', $data['device_token']);
        $auth_token_id = $this->db->fetchValue(
            'SELECT auth_token_id FROM auth_tokens
             WHERE user_id = :user_id AND auth_token_hash = :auth_token_hash
               AND auth_token_revoked_at IS NULL AND auth_token_expires_at > UTC_TIMESTAMP()',
            ['user_id' => $user_id, 'auth_token_hash' => hash('sha256', $auth_token)]
        );
        if ($auth_token_id === null) {
            throw ApiException::unauthenticated();
        }

        $this->db->execute(
            'INSERT INTO device_tokens (user_id, auth_token_id, device_token_hash, device_token, device_platform, device_language, last_seen_at)
             VALUES (:user_id, :auth_token_id, :token_hash, :device_token, :platform, :language, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), auth_token_id = VALUES(auth_token_id),
                                     device_token = VALUES(device_token),
                                     device_platform = VALUES(device_platform),
                                     device_language = VALUES(device_language), last_seen_at = UTC_TIMESTAMP()',
            [
                'user_id'      => $user_id,
                'auth_token_id' => $auth_token_id,
                'token_hash'   => $token_hash,
                'device_token' => $data['device_token'],
                'platform'     => $data['device_platform'],
                'language'     => $data['device_language'] ?? 'sw',
            ]
        );
    }

    /** Removes this app installation's registration on logout. */
    public function remove(int $user_id, array $input): void
    {
        $data = Validator::validate($input, ['device_token' => self::RULES['device_token']]);
        $this->db->execute(
            'DELETE FROM device_tokens WHERE user_id = :user_id AND device_token_hash = :token_hash',
            ['user_id' => $user_id, 'token_hash' => hash('sha256', $data['device_token'])]
        );
    }

    /** Device registrations for one customer; used only by the push sender. */
    public function forUser(int $user_id): array
    {
        return $this->db->fetchAll(
            'SELECT d.device_token_id, d.device_token, d.device_language
             FROM device_tokens d
             INNER JOIN auth_tokens a ON a.auth_token_id = d.auth_token_id
             WHERE d.user_id = :user_id AND a.auth_token_revoked_at IS NULL
               AND a.auth_token_expires_at > UTC_TIMESTAMP()',
            ['user_id' => $user_id]
        );
    }

    /** FCM says this registration no longer exists. */
    public function removeById(int $device_token_id): void
    {
        $this->db->execute(
            'DELETE FROM device_tokens WHERE device_token_id = :device_token_id',
            ['device_token_id' => $device_token_id]
        );
    }
}
