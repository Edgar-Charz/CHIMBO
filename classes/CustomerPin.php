<?php

/**
 * The customer's 4–6 digit PIN (like M-Pesa): the everyday way to log in once the phone is verified.
 *
 * Safety rules:
 * - only a hash is stored: bcrypt (password_hash) of an HMAC keyed with APP_KEY, so a leaked
 *   database alone is not enough to try all 1,000,000 PINs
 * - 5 wrong PINs in a row lock the PIN; only a new PIN (after an SMS code) unlocks it,
 *   so a stranger gets 5 guesses in total, never more
 * - PINs that are easy to guess (0000, 1234, 4321, the end of the phone number …) are refused
 *
 * How to use it:
 *   $customer_pin = new CustomerPin(Database::instance());
 *   $user_id = $customer_pin->checkPinForPhone('+255712345678', '4826');   // throws when wrong or locked
 */
class CustomerPin
{
    public const MAX_FAILED_ATTEMPTS = 5;
    private const MIN_LENGTH = 4;
    private const MAX_LENGTH = 6;

    public function __construct(private Database $db)
    {
    }

    public function hasPin(int $user_id): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT user_pin_hash IS NOT NULL FROM users WHERE user_id = :user_id',
            ['user_id' => $user_id]
        );
    }

    /** Login: returns the user id when the PIN is right; throws PIN_INVALID or PIN_LOCKED otherwise. */
    public function checkPinForPhone(string $phone, string $pin): int
    {
        return $this->checkPin('user_phone = :value', $phone, $pin);
    }

    /** "Badilisha PIN": the current PIN must be right (wrong tries count towards the lock too). */
    public function checkPinForUser(int $user_id, string $pin): void
    {
        $this->checkPin('user_id = :value', $user_id, $pin);
    }

    /**
     * Checks a new PIN from the form (user_pin + user_pin_confirmation) and returns it.
     * The PIN is sent as text so a leading zero is kept ("0482").
     */
    public function validateNewPin(array $data, string $phone): string
    {
        $pin = $data['user_pin'];

        $error = match (true) {
            !ctype_digit($pin) || strlen($pin) < self::MIN_LENGTH || strlen($pin) > self::MAX_LENGTH
                => 'PIN iwe tarakimu ' . self::MIN_LENGTH . ' hadi ' . self::MAX_LENGTH . '.',
            $this->isEasyToGuess($pin, $phone)
                => 'PIN hii ni rahisi kukisia, chagua nyingine.',
            default => null,
        };
        if ($error !== null) {
            throw ApiException::validation(['user_pin' => $error]);
        }

        if (!hash_equals($pin, $data['user_pin_confirmation'])) {
            throw ApiException::validation(['user_pin_confirmation' => 'PIN hazifanani. Andika PIN ile ile mara mbili.']);
        }

        return $pin;
    }

    /** Saves a new PIN and unlocks a locked one. */
    public function savePin(int $user_id, string $pin): void
    {
        $this->db->execute(
            'UPDATE users
             SET user_pin_hash = :pin_hash, user_pin_failed_attempts = 0, user_pin_locked_at = NULL,
                 user_pin_changed_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id',
            ['pin_hash' => password_hash($this->pepper($pin), PASSWORD_DEFAULT), 'user_id' => $user_id]
        );
    }

    /**
     * Staff button "Lazimisha kubadili PIN" (e.g. the customer thinks someone knows their PIN):
     * locks the PIN and logs the customer out everywhere. Their next login goes through an SMS code.
     */
    public function forcePinReset(int $user_id, int $admin_id): void
    {
        $this->db->transaction(function (Database $db) use ($user_id, $admin_id) {
            $changed = $db->execute(
                'UPDATE users SET user_pin_locked_at = UTC_TIMESTAMP(), user_sessions_revoked_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id AND user_pin_hash IS NOT NULL',
                ['user_id' => $user_id]
            );
            if ($changed === 0) {
                throw ApiException::notFound('This customer has no PIN to reset.');
            }

            (new AuthToken($db))->revokeAllTokensForUser($user_id);
            (new AuditLog($db))->record('admin', $admin_id, 'customer.pin_reset_forced', 'user', $user_id);
        });
    }

    /**
     * The shared check. The row is locked (FOR UPDATE) so two tries at the same moment are both counted.
     * The result is decided inside the transaction and the error thrown outside, so a wrong try is saved.
     */
    private function checkPin(string $where_sql, string|int $value, string $pin): int
    {
        $result = $this->db->transaction(function (Database $db) use ($where_sql, $value, $pin) {
            $user = $db->fetchOne(
                "SELECT user_id, user_status, user_pin_hash, user_pin_failed_attempts, user_pin_locked_at
                 FROM users WHERE {$where_sql} FOR UPDATE",
                ['value' => $value]
            );

            // Unknown number or no PIN: the same answer as a wrong PIN (nothing to count)
            if ($user === null || $user['user_pin_hash'] === null) {
                return ['outcome' => 'wrong', 'attempts_left' => null];
            }
            if ($user['user_status'] !== 'active') {
                return ['outcome' => 'suspended'];
            }
            if ($user['user_pin_locked_at'] !== null) {
                return ['outcome' => 'locked'];
            }

            if (!password_verify($this->pepper($pin), $user['user_pin_hash'])) {
                $failed_attempts = (int) $user['user_pin_failed_attempts'] + 1;
                $db->execute(
                    'UPDATE users
                     SET user_pin_failed_attempts = :failed_attempts,
                         user_pin_locked_at = IF(:locks_now, UTC_TIMESTAMP(), NULL)
                     WHERE user_id = :user_id',
                    [
                        'failed_attempts' => $failed_attempts,
                        'locks_now'       => (int) ($failed_attempts >= self::MAX_FAILED_ATTEMPTS),
                        'user_id'         => $user['user_id'],
                    ]
                );
                return $failed_attempts >= self::MAX_FAILED_ATTEMPTS
                    ? ['outcome' => 'locked']
                    : ['outcome' => 'wrong', 'attempts_left' => self::MAX_FAILED_ATTEMPTS - $failed_attempts];
            }

            $db->execute(
                'UPDATE users SET user_pin_failed_attempts = 0, user_last_login_at = UTC_TIMESTAMP() WHERE user_id = :user_id',
                ['user_id' => $user['user_id']]
            );
            return ['outcome' => 'correct', 'user_id' => (int) $user['user_id']];
        });

        return match ($result['outcome']) {
            'correct'   => $result['user_id'],
            'suspended' => throw ApiException::forbidden('Akaunti yako imesimamishwa. Wasiliana na CHIMBO kwa msaada.'),
            'locked'    => throw new ApiException(423, 'PIN_LOCKED',
                'PIN imefungwa baada ya kukosea mara ' . self::MAX_FAILED_ATTEMPTS . '. Bonyeza "Umesahau PIN?" kuweka PIN mpya.'),
            'wrong'     => throw new ApiException(422, 'PIN_INVALID', $result['attempts_left'] === null
                ? 'Namba ya simu au PIN si sahihi.'
                : "Namba ya simu au PIN si sahihi. Umebakiza majaribio {$result['attempts_left']}."),
        };
    }

    /** 0000, 1111 · 1234, 3456, 4321, 123456 · the last digits of the customer's own phone number. */
    private function isEasyToGuess(string $pin, string $phone): bool
    {
        $all_same_digit = count(array_unique(str_split($pin))) === 1;
        $counts_up      = str_contains('01234567890', $pin);
        $counts_down    = str_contains('09876543210', $pin);
        $end_of_phone   = str_ends_with($phone, $pin);

        return $all_same_digit || $counts_up || $counts_down || $end_of_phone;
    }

    /** The PIN mixed with APP_KEY before bcrypt (short PINs need this extra key). */
    private function pepper(string $pin): string
    {
        return hash_hmac('sha256', $pin, Env::required('APP_KEY'));
    }
}
