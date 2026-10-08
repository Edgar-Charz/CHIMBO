<?php

/**
 * Customer login for both the mobile app and the website.
 *
 *   startLogin()        → the phone number decides the next screen: "pin", "pin_locked" or "otp"
 *                         (for "otp" the SMS code is sent straight away)
 *   New number:         verifyLoginCode() → savePin() (Tengeneza PIN) → User::completeProfile() (business details)
 *   Registered number:  logInWithPin()
 *   "Umesahau PIN?":    requestLoginCode() → verifyLoginCode() → savePin() without the old PIN
 *
 * Every login gives the app a token and the website a session. The profile's "user_has_pin" and
 * "is_profile_complete" tell the apps which step is still missing.
 */
class CustomerAuth
{
    // What the apps send with every login
    private const CLIENT_RULES = [
        'client'                 => 'required|in:app,web',
        'auth_token_device_name' => 'nullable|string|max:100',
        'auth_token_platform'    => 'nullable|in:android,ios',
    ];

    private Otp $otp;
    private User $user_model;
    private CustomerPin $customer_pin;

    public function __construct(private Database $db)
    {
        $this->otp          = new Otp($db);
        $this->user_model   = new User($db);
        $this->customer_pin = new CustomerPin($db);
    }

    /**
     * Step 1 of every login: the customer types their phone number.
     * Returns next_step: "pin" (show the PIN screen), "pin_locked" (show "Umesahau PIN?"),
     * or "otp" (new number or no PIN yet — the SMS code has been sent, same fields as requestLoginCode).
     */
    public function startLogin(array $input, string $ip_address): array
    {
        $data = Validator::validate($input, ['user_phone' => 'required|phone_tz']);

        // This answer shows whether a number is registered, so nobody may check many numbers
        (new RateLimiter($this->db))->hit("login-start:ip:{$ip_address}", 30, 3600);

        $user = $this->db->fetchOne(
            'SELECT user_status, user_pin_hash IS NOT NULL AS has_pin, user_pin_locked_at IS NOT NULL AS is_locked
             FROM users WHERE user_phone = :user_phone',
            ['user_phone' => $data['user_phone']]
        );

        if ($user !== null && $user['user_status'] !== 'active') {
            throw ApiException::forbidden('Akaunti yako imesimamishwa. Wasiliana na CHIMBO kwa msaada.');
        }
        if ($user !== null && $user['has_pin']) {
            return ['user_phone' => $data['user_phone'], 'next_step' => $user['is_locked'] ? 'pin_locked' : 'pin'];
        }

        return ['next_step' => 'otp'] + $this->requestLoginCode($data, $ip_address);
    }

    /** Login with the phone number and PIN. Same answer as verifyLoginCode(). */
    public function logInWithPin(array $input, string $ip_address): array
    {
        $data = Validator::validate($input, [
            'user_phone' => 'required|phone_tz',
            'user_pin'   => 'required|string|max:6',
        ] + self::CLIENT_RULES);

        // Each number locks after 5 wrong PINs; this stops one device from trying many numbers
        (new RateLimiter($this->db))->hit("pin-login:ip:{$ip_address}", 60, 3600);

        $user_id = $this->customer_pin->checkPinForPhone($data['user_phone'], $data['user_pin']);

        return $this->logIn($user_id, $data, false);
    }

    /**
     * "Tengeneza PIN" (after the first SMS code), "Umesahau PIN?" (after an SMS code on this device)
     * and "Badilisha PIN" in Wasifu (needs current_pin). Replacing a PIN logs out every other device.
     * Input: user_pin, user_pin_confirmation, current_pin (only when changing). Returns the profile.
     */
    public function savePin(int $user_id, ?string $bearer_token, array $input): array
    {
        $data = Validator::validate($input, [
            'user_pin'              => 'required|string|max:6',
            'user_pin_confirmation' => 'required|string|max:6',
            'current_pin'           => 'nullable|string|max:6',
        ]);

        $is_replacing = $this->customer_pin->hasPin($user_id);
        $may_reset    = $bearer_token !== null ? (new AuthToken($this->db))->mayResetPin($bearer_token) : CustomerSession::mayResetPin();

        if ($is_replacing && !$may_reset) {
            if (!isset($data['current_pin'])) {
                throw ApiException::validation(['current_pin' => 'Andika PIN yako ya sasa.']);
            }
            $this->customer_pin->checkPinForUser($user_id, $data['current_pin']);
        }

        $phone = (string) $this->db->fetchValue('SELECT user_phone FROM users WHERE user_id = :user_id', ['user_id' => $user_id]);
        $pin   = $this->customer_pin->validateNewPin($data, $phone);

        $this->db->transaction(function () use ($user_id, $pin, $is_replacing, $bearer_token) {
            $this->customer_pin->savePin($user_id, $pin);
            if ($is_replacing) {
                (new AuthToken($this->db))->revokeAllTokensForUser($user_id, $bearer_token);
                $this->user_model->revokeWebsiteSessions($user_id);
            }
        });

        // This device stays logged in; its SMS-code right is used up
        if ($bearer_token !== null) {
            (new AuthToken($this->db))->endPinReset($bearer_token);
        } else {
            CustomerSession::logIn($user_id);   // a fresh session, newer than the "log out everywhere" above
        }

        return $this->user_model->getProfile($user_id);
    }

    /** Step 1. Returns how long the code lasts and when "Tuma tena" is allowed. */
    public function requestLoginCode(array $input, string $ip_address): array
    {
        $data = Validator::validate($input, ['user_phone' => 'required|phone_tz']);

        (new RateLimiter($this->db))->hit("otp-request:ip:{$ip_address}", 20, 3600);

        $code    = $this->otp->createLoginCode($data['user_phone'], $ip_address);
        $minutes = intdiv($this->otp->lifetimeSeconds(), 60);

        (new SmsSender($this->db))->send(
            $data['user_phone'],
            "CHIMBO: Namba yako ya uthibitisho ni {$code}. Inaisha baada ya dakika {$minutes}. Usimpe mtu yeyote.",
            'otp',
            'CHIMBO: Namba yako ya uthibitisho ni ******.' // stored in sms_outbox without the code
        );

        $result = [
            'user_phone'               => $data['user_phone'],
            'otp_expires_in_seconds'   => $this->otp->lifetimeSeconds(),
            'otp_resend_after_seconds' => $this->otp->resendWaitSeconds(),
        ];

        // Development and beta testing only: return the code so the apps work without real SMS
        if (self::shouldShowCodeInResponse()) {
            $result['debug_otp_code'] = $code;
        }

        return $result;
    }

    /**
     * Step 2. Checks the code and logs the customer in.
     * App ("client": "app")  → returns a token to send as "Authorization: Bearer <token>".
     * Website ("client": "web") → starts the website session (no token).
     */
    public function verifyLoginCode(array $input, string $ip_address): array
    {
        $data = Validator::validate($input, [
            'user_phone' => 'required|phone_tz',
            'otp_code'   => 'required|string|digits:6',
        ] + self::CLIENT_RULES);

        (new RateLimiter($this->db))->hit("otp-verify:ip:{$ip_address}", 30, 3600);

        $this->otp->verifyLoginCode($data['user_phone'], $data['otp_code']);
        $user_id = $this->user_model->findOrCreateUserIdByPhone($data['user_phone']);

        return $this->logIn($user_id, $data, true);
    }

    /** Logout: revokes the app token, or ends the website session. */
    public function logOut(?string $bearer_token): void
    {
        if ($bearer_token !== null) {
            (new AuthToken($this->db))->revokeToken($bearer_token);
            return;
        }
        CustomerSession::logOut();
    }

    /**
     * Gives the app a token or starts the website session. Returns {user, auth_token, auth_token_expires_at}
     * (the website gets only {user}). $proved_by_sms = the login used an SMS code (allows a new PIN without the old one).
     */
    private function logIn(int $user_id, array $data, bool $proved_by_sms): array
    {
        $result = ['user' => $this->user_model->getProfile($user_id)];

        if ($data['client'] === 'app') {
            $result += (new AuthToken($this->db))->createToken(
                $user_id,
                $data['auth_token_device_name'] ?? null,
                $data['auth_token_platform'] ?? null,
                $proved_by_sms
            );
        } else {
            CustomerSession::logIn($user_id, $proved_by_sms);
        }

        return $result;
    }

    /**
     * "Show code" mode for development and beta testing (OTP_SHOW_CODE=true in .env):
     * the login code is returned to the app instead of arriving by SMS.
     *
     * Anyone who knows a phone number could log in as that person while this is on, so it is
     * ALWAYS off in production (and when APP_ENV is missing), whatever OTP_SHOW_CODE says.
     * Use it only on your computer or a staging server with test data.
     */
    public static function shouldShowCodeInResponse(): bool
    {
        $is_production = Env::get('APP_ENV', 'production') === 'production';
        return !$is_production && Env::get('OTP_SHOW_CODE', false) === true;
    }
}
