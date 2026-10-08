<?php

/**
 * Login tokens for the mobile app ("Authorization: Bearer <token>").
 *
 * - The token is 64 random characters; the app keeps it in secure storage.
 * - Only a SHA-256 hash is stored, so a leaked database cannot be used to log in.
 * - It lasts LIFETIME_DAYS since the last use (using the app keeps you logged in).
 * - Logout revokes it immediately.
 */
class AuthToken
{
    private const LIFETIME_DAYS = 60;
    private const PIN_RESET_MINUTES = 15;   // after an SMS code, this device may set a new PIN for this long

    public function __construct(private Database $db)
    {
    }

    /**
     * Creates a token for the user. Returns the plain token (shown to the app once) and its expiry.
     * $proved_by_sms = the login used an SMS code, so this device may set a new PIN without the old one
     * ("Umesahau PIN?") for PIN_RESET_MINUTES.
     */
    public function createToken(int $user_id, ?string $device_name, ?string $platform, bool $proved_by_sms = false): array
    {
        $plain_token = bin2hex(random_bytes(32));

        $token_id = $this->db->insert(
            'INSERT INTO auth_tokens (user_id, auth_token_hash, auth_token_device_name, auth_token_platform,
                                      auth_token_expires_at, auth_token_pin_reset_until)
             VALUES (:user_id, :auth_token_hash, :device_name, :platform,
                     UTC_TIMESTAMP() + INTERVAL ' . self::LIFETIME_DAYS . ' DAY,
                     IF(:proved_by_sms, UTC_TIMESTAMP() + INTERVAL ' . self::PIN_RESET_MINUTES . ' MINUTE, NULL))',
            [
                'user_id'         => $user_id,
                'auth_token_hash' => hash('sha256', $plain_token),
                'device_name'     => $device_name,
                'platform'        => $platform,
                'proved_by_sms'   => (int) $proved_by_sms,
            ]
        );

        $expires_at = $this->db->fetchValue(
            'SELECT auth_token_expires_at FROM auth_tokens WHERE auth_token_id = :auth_token_id',
            ['auth_token_id' => $token_id]
        );

        return ['auth_token' => $plain_token, 'auth_token_expires_at' => isoDate($expires_at)];
    }

    /** The user id for a valid token (not expired, not revoked), or null. Also extends the token's life. */
    public function findUserIdByToken(string $plain_token): ?int
    {
        $token = $this->db->fetchOne(
            'SELECT auth_token_id, user_id,
                    auth_token_last_used_at IS NULL
                    OR auth_token_last_used_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR AS needs_refresh
             FROM auth_tokens
             WHERE auth_token_hash = :auth_token_hash
               AND auth_token_revoked_at IS NULL
               AND auth_token_expires_at > UTC_TIMESTAMP()',
            ['auth_token_hash' => hash('sha256', $plain_token)]
        );

        if ($token === null) {
            return null;
        }

        // Extend the token's life at most once an hour — not on every request (saves a database write per tap)
        if ($token['needs_refresh']) {
            $this->db->execute(
                'UPDATE auth_tokens
                 SET auth_token_last_used_at = UTC_TIMESTAMP(),
                     auth_token_expires_at   = UTC_TIMESTAMP() + INTERVAL ' . self::LIFETIME_DAYS . ' DAY
                 WHERE auth_token_id = :auth_token_id',
                ['auth_token_id' => $token['auth_token_id']]
            );
        }

        return (int) $token['user_id'];
    }

    /** Logout: the token stops working immediately. */
    public function revokeToken(string $plain_token): void
    {
        $this->db->execute(
            'UPDATE auth_tokens SET auth_token_revoked_at = UTC_TIMESTAMP()
             WHERE auth_token_hash = :auth_token_hash AND auth_token_revoked_at IS NULL',
            ['auth_token_hash' => hash('sha256', $plain_token)]
        );
    }

    /**
     * Logs the user out on every device (account suspended or deleted, PIN changed).
     * $keep_plain_token = the device making the change stays logged in.
     */
    public function revokeAllTokensForUser(int $user_id, ?string $keep_plain_token = null): void
    {
        $this->db->execute(
            'UPDATE auth_tokens SET auth_token_revoked_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND auth_token_revoked_at IS NULL AND auth_token_hash <> :keep_hash',
            ['user_id' => $user_id, 'keep_hash' => $keep_plain_token === null ? '' : hash('sha256', $keep_plain_token)]
        );
    }

    /** True while this device may set a new PIN without the old one (shortly after an SMS code). */
    public function mayResetPin(string $plain_token): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM auth_tokens
             WHERE auth_token_hash = :auth_token_hash AND auth_token_pin_reset_until > UTC_TIMESTAMP()',
            ['auth_token_hash' => hash('sha256', $plain_token)]
        );
    }

    /** The right to reset the PIN is used once. */
    public function endPinReset(string $plain_token): void
    {
        $this->db->execute(
            'UPDATE auth_tokens SET auth_token_pin_reset_until = NULL WHERE auth_token_hash = :auth_token_hash',
            ['auth_token_hash' => hash('sha256', $plain_token)]
        );
    }
}
