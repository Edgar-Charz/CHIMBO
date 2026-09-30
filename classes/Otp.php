<?php

/**
 * One-time login codes (the 6 digits sent by SMS).
 *
 * Safety rules (limits come from the `settings` table):
 * - only a keyed hash of the code is stored (HMAC with APP_KEY), never the code itself
 * - a code expires after otp_lifetime_seconds and works only once
 * - after otp_max_attempts wrong guesses the code is blocked
 * - "Tuma tena" must wait otp_resend_wait_seconds; at most otp_max_requests_per_hour per phone
 * - asking for a new code cancels the previous one
 */
class Otp
{
    private Settings $settings;
    private RateLimiter $rate_limiter;

    public function __construct(private Database $db)
    {
        $this->settings     = new Settings($db);
        $this->rate_limiter = new RateLimiter($db);
    }

    /** Creates a new code for this phone and returns it (the caller sends it by SMS). */
    public function createLoginCode(string $phone, string $ip_address): string
    {
        $this->checkResendWait($phone);

        $this->rate_limiter->hit(
            "otp-request:phone:{$phone}",
            $this->settings->getInt('otp_max_requests_per_hour', 5),
            3600,
            'Umeomba namba nyingi mno. Tafadhali jaribu tena baada ya saa moja.'
        );

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->db->transaction(function (Database $db) use ($phone, $code, $ip_address) {
            // Cancel older unused codes, so only the newest one works
            $db->execute(
                'UPDATE otp_codes SET otp_consumed_at = UTC_TIMESTAMP()
                 WHERE otp_phone = :otp_phone AND otp_consumed_at IS NULL',
                ['otp_phone' => $phone]
            );

            $db->insert(
                'INSERT INTO otp_codes (otp_phone, otp_code_hash, otp_expires_at, otp_ip_address)
                 VALUES (:otp_phone, :otp_code_hash, UTC_TIMESTAMP() + INTERVAL :lifetime_seconds SECOND, :otp_ip_address)',
                [
                    'otp_phone'        => $phone,
                    'otp_code_hash'    => $this->hashCode($phone, $code),
                    'lifetime_seconds' => $this->lifetimeSeconds(),
                    'otp_ip_address'   => $ip_address,
                ]
            );
        });

        return $code;
    }

    /** Checks the code the customer typed. Returns nothing when correct; throws a clear error otherwise. */
    public function verifyLoginCode(string $phone, string $code): void
    {
        // The check runs inside a transaction with the row locked (FOR UPDATE), so two requests
        // at the same time cannot both use one code. The result is decided inside and the error
        // thrown outside, so the "wrong attempt" counter is saved even when the code is wrong.
        $result = $this->db->transaction(function (Database $db) use ($phone, $code) {
            $otp = $db->fetchOne(
                'SELECT otp_id, otp_code_hash, otp_attempts, otp_expires_at < UTC_TIMESTAMP() AS is_expired
                 FROM otp_codes
                 WHERE otp_phone = :otp_phone AND otp_consumed_at IS NULL
                 ORDER BY otp_id DESC
                 LIMIT 1
                 FOR UPDATE',
                ['otp_phone' => $phone]
            );

            if ($otp === null || $otp['is_expired']) {
                return 'expired';
            }
            if ($otp['otp_attempts'] >= $this->settings->getInt('otp_max_attempts', 5)) {
                return 'too_many_attempts';
            }
            if (!hash_equals($otp['otp_code_hash'], $this->hashCode($phone, $code))) {
                $db->execute('UPDATE otp_codes SET otp_attempts = otp_attempts + 1 WHERE otp_id = :otp_id', ['otp_id' => $otp['otp_id']]);
                return 'wrong_code';
            }

            $db->execute('UPDATE otp_codes SET otp_consumed_at = UTC_TIMESTAMP() WHERE otp_id = :otp_id', ['otp_id' => $otp['otp_id']]);
            return 'correct';
        });

        match ($result) {
            'correct'           => null,
            'expired'           => throw new ApiException(422, 'OTP_EXPIRED', 'Namba ya uthibitisho imeisha muda. Omba namba mpya.'),
            'too_many_attempts' => throw new ApiException(429, 'OTP_TOO_MANY_ATTEMPTS', 'Umekosea mara nyingi. Tafadhali omba namba mpya.'),
            'wrong_code'        => throw new ApiException(422, 'OTP_INVALID', 'Namba ya uthibitisho si sahihi.'),
        };
    }

    public function lifetimeSeconds(): int
    {
        return $this->settings->getInt('otp_lifetime_seconds', 300);
    }

    public function resendWaitSeconds(): int
    {
        return $this->settings->getInt('otp_resend_wait_seconds', 60);
    }

    /** "Tuma tena" is only allowed after the wait time since the last code. */
    private function checkResendWait(string $phone): void
    {
        $seconds_since_last_code = $this->db->fetchValue(
            'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) FROM otp_codes WHERE otp_phone = :otp_phone',
            ['otp_phone' => $phone]
        );

        if ($seconds_since_last_code !== null && $seconds_since_last_code < $this->resendWaitSeconds()) {
            $seconds_left = $this->resendWaitSeconds() - (int) $seconds_since_last_code;
            throw new ApiException(429, 'OTP_RESEND_TOO_SOON', "Subiri sekunde {$seconds_left} kabla ya kuomba namba nyingine.");
        }
    }

    /** Keyed hash of phone + code. Without APP_KEY, a leaked table cannot be used to find codes. */
    private function hashCode(string $phone, string $code): string
    {
        return hash_hmac('sha256', "{$phone}|{$code}", Env::required('APP_KEY'));
    }
}
