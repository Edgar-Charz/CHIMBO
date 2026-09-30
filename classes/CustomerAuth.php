<?php

/**
 * Customer login with phone + OTP, for both the mobile app and the website.
 * There is no password: registration and login are the same two steps.
 *
 *   1. requestLoginCode()  → sends a 6-digit code by SMS
 *   2. verifyLoginCode()   → checks the code; creates the account on the first login;
 *                            app gets a token, website gets a session
 *   3. (new users) User::completeProfile() → name + business ("Tuambie Kuhusu Biashara Yako")
 */
class CustomerAuth
{
    private Otp $otp;
    private User $user_model;

    public function __construct(private Database $db)
    {
        $this->otp        = new Otp($db);
        $this->user_model = new User($db);
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
            'user_phone'             => 'required|phone_tz',
            'otp_code'               => 'required|string|digits:6',
            'client'                 => 'required|in:app,web',
            'auth_token_device_name' => 'nullable|string|max:100',
            'auth_token_platform'    => 'nullable|in:android,ios',
        ]);

        (new RateLimiter($this->db))->hit("otp-verify:ip:{$ip_address}", 30, 3600);

        $this->otp->verifyLoginCode($data['user_phone'], $data['otp_code']);
        $user_id = $this->user_model->findOrCreateUserIdByPhone($data['user_phone']);

        $result = ['user' => $this->user_model->getProfile($user_id)];

        if ($data['client'] === 'app') {
            $result += (new AuthToken($this->db))->createToken(
                $user_id,
                $data['auth_token_device_name'] ?? null,
                $data['auth_token_platform'] ?? null
            );
        } else {
            CustomerSession::logIn($user_id);
        }

        return $result;
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
