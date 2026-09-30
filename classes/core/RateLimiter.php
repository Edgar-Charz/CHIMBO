<?php

/**
 * Stops abuse by counting attempts per key in a time window, e.g.
 * "at most 5 OTP requests per phone number per hour".
 *
 * How to use it:
 *   $rate_limiter = new RateLimiter(Database::instance());
 *   $rate_limiter->hit('otp-request:phone:+255712345678', 5, 3600);   // throws 429 on the 6th try
 *
 * The time is cut into fixed windows (e.g. 10:00–11:00); each window has its own counter row.
 */
class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /** Counts one attempt; throws a 429 error when the limit for this window is passed. */
    public function hit(
        string $key,
        int $max_attempts,
        int $window_seconds,
        string $message = 'Maombi mengi mno. Tafadhali subiri kidogo kisha ujaribu tena.'
    ): void {
        // Start of the current window, e.g. every hour: 10:00:00, 11:00:00 …
        $window_start = gmdate('Y-m-d H:i:s', intdiv(time(), $window_seconds) * $window_seconds);

        // Add 1 to this window's counter (creates the row the first time)
        $this->db->execute(
            'INSERT INTO rate_limits (rate_limit_key, rate_limit_window_start, rate_limit_hits)
             VALUES (:rate_limit_key, :window_start, 1)
             ON DUPLICATE KEY UPDATE rate_limit_hits = rate_limit_hits + 1',
            ['rate_limit_key' => $key, 'window_start' => $window_start]
        );

        $hits = (int) $this->db->fetchValue(
            'SELECT rate_limit_hits FROM rate_limits
             WHERE rate_limit_key = :rate_limit_key AND rate_limit_window_start = :window_start',
            ['rate_limit_key' => $key, 'window_start' => $window_start]
        );

        $this->deleteOldWindowsSometimes();

        if ($hits > $max_attempts) {
            throw ApiException::tooManyRequests($message);
        }
    }

    /** About once in 100 calls, removes counters older than a day so the table stays small. */
    private function deleteOldWindowsSometimes(): void
    {
        if (random_int(1, 100) === 1) {
            $this->db->execute('DELETE FROM rate_limits WHERE rate_limit_window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        }
    }
}
