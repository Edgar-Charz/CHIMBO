<?php

use PHPUnit\Framework\TestCase;

/**
 * PIN login: phone → PIN for registered customers, phone → SMS code → create PIN for new ones,
 * "Umesahau PIN?" and "Badilisha PIN". Each test proves one rule from blueprint §12.5.
 */
final class CustomerPinTest extends TestCase
{
    private const PHONE      = '+255712000111';
    private const IP_ADDRESS = '127.0.0.1';
    private const PIN        = '4826';

    private Database $db;
    private CustomerAuth $customer_auth;
    private AuthToken $auth_token;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        Settings::clearCache();
        Env::set('APP_ENV', 'local');        // read the SMS code from the response
        Env::set('OTP_SHOW_CODE', 'true');

        $this->customer_auth = new CustomerAuth($this->db);
        $this->auth_token    = new AuthToken($this->db);
    }

    protected function tearDown(): void
    {
        Env::set('APP_ENV', 'testing');
        Env::set('OTP_SHOW_CODE', 'false');
    }

    public function testNewNumberGetsAnSmsCodeThenCreatesAPin(): void
    {
        $start = $this->customer_auth->startLogin(['user_phone' => '0712000111'], self::IP_ADDRESS);
        $this->assertSame('otp', $start['next_step']);
        $this->assertArrayHasKey('debug_otp_code', $start);   // the code was sent straight away

        $login = $this->customer_auth->verifyLoginCode($this->otpLoginForm($start['debug_otp_code']), self::IP_ADDRESS);
        $this->assertFalse($login['user']['user_has_pin']);  // → "Tengeneza PIN" screen

        $profile = $this->savePin($login, ['user_pin' => self::PIN, 'user_pin_confirmation' => self::PIN]);
        $this->assertTrue($profile['user_has_pin']);
        $this->assertFalse($profile['is_profile_complete']); // → business details next

        $this->assertSame('pin', $this->customer_auth->startLogin(['user_phone' => self::PHONE], self::IP_ADDRESS)['next_step']);
    }

    public function testRegisteredCustomerLogsInWithThePin(): void
    {
        $this->registerWithPin();

        $login = $this->customer_auth->logInWithPin($this->pinLoginForm(self::PIN), self::IP_ADDRESS);

        $this->assertSame(self::PHONE, $login['user']['user_phone']);
        $this->assertTrue($login['user']['user_has_pin']);
        $this->assertSame(64, strlen($login['auth_token']));
    }

    public function testWrongPinSaysHowManyTriesAreLeft(): void
    {
        $this->registerWithPin();

        $e = $this->catchApiError(fn () => $this->customer_auth->logInWithPin($this->pinLoginForm('9713'), self::IP_ADDRESS));

        $this->assertSame('PIN_INVALID', $e->errorCode());
        $this->assertStringContainsString('majaribio 4', $e->getMessage());
    }

    public function testFiveWrongPinsLockThePinEvenForTheRightPin(): void
    {
        $this->registerWithPin();

        for ($try = 1; $try <= CustomerPin::MAX_FAILED_ATTEMPTS; $try++) {
            $e = $this->catchApiError(fn () => $this->customer_auth->logInWithPin($this->pinLoginForm('9713'), self::IP_ADDRESS));
        }
        $this->assertSame('PIN_LOCKED', $e->errorCode());

        $e = $this->catchApiError(fn () => $this->customer_auth->logInWithPin($this->pinLoginForm(self::PIN), self::IP_ADDRESS));
        $this->assertSame('PIN_LOCKED', $e->errorCode());
        $this->assertSame('pin_locked', $this->customer_auth->startLogin(['user_phone' => self::PHONE], self::IP_ADDRESS)['next_step']);
    }

    public function testRightPinResetsTheWrongTriesCounter(): void
    {
        $this->registerWithPin();

        $this->catchApiError(fn () => $this->customer_auth->logInWithPin($this->pinLoginForm('9713'), self::IP_ADDRESS));
        $this->customer_auth->logInWithPin($this->pinLoginForm(self::PIN), self::IP_ADDRESS);

        $this->assertSame(0, (int) $this->db->fetchValue('SELECT user_pin_failed_attempts FROM users'));
    }

    public function testForgotPinAfterAnSmsCodeUnlocksAndLogsOutOtherDevices(): void
    {
        $old_device = $this->registerWithPin();
        $this->db->execute('UPDATE users SET user_pin_locked_at = UTC_TIMESTAMP()');

        // "Umesahau PIN?" → SMS code on the new device → new PIN, without the old one
        $this->db->execute('DELETE FROM otp_codes');   // skip the "Tuma tena" wait
        $code       = $this->customer_auth->requestLoginCode(['user_phone' => self::PHONE], self::IP_ADDRESS)['debug_otp_code'];
        $new_device = $this->customer_auth->verifyLoginCode($this->otpLoginForm($code), self::IP_ADDRESS);
        $this->savePin($new_device, ['user_pin' => '5930', 'user_pin_confirmation' => '5930']);

        $this->assertNull($this->auth_token->findUserIdByToken($old_device['auth_token']));     // logged out
        $this->assertNotNull($this->auth_token->findUserIdByToken($new_device['auth_token']));  // still logged in
        $this->customer_auth->logInWithPin($this->pinLoginForm('5930'), self::IP_ADDRESS);       // unlocked
    }

    public function testTheSmsCodeAllowsOnlyOnePinChangeWithoutTheOldPin(): void
    {
        $device = $this->registerWithPin();   // this device used an SMS code, and its right was used for the first PIN

        $e = $this->catchApiError(fn () => $this->savePin($device, ['user_pin' => '5930', 'user_pin_confirmation' => '5930']));

        $this->assertSame(['current_pin' => 'Andika PIN yako ya sasa.'], $e->fields());
    }

    public function testChangingThePinInWasifuNeedsTheCurrentPin(): void
    {
        $this->registerWithPin();
        $device = $this->customer_auth->logInWithPin($this->pinLoginForm(self::PIN), self::IP_ADDRESS);
        $other  = $this->customer_auth->logInWithPin($this->pinLoginForm(self::PIN), self::IP_ADDRESS);

        $e = $this->catchApiError(fn () => $this->savePin($device, ['user_pin' => '5930', 'user_pin_confirmation' => '5930', 'current_pin' => '9713']));
        $this->assertSame('PIN_INVALID', $e->errorCode());

        $this->savePin($device, ['user_pin' => '5930', 'user_pin_confirmation' => '5930', 'current_pin' => self::PIN]);

        $this->assertNotNull($this->auth_token->findUserIdByToken($device['auth_token']));
        $this->assertNull($this->auth_token->findUserIdByToken($other['auth_token']));
    }

    public function testPinsThatAreEasyToGuessOrBadlyTypedAreRefused(): void
    {
        $login = $this->otpLogin();

        $refused = [
            '1234'   => 'user_pin',                // counts up
            '9876'   => 'user_pin',                // counts down
            '0000'   => 'user_pin',                // one digit
            '000111' => 'user_pin',                // the end of the phone number
            '123'    => 'user_pin',                // too short
            '12a4'   => 'user_pin',                // not only digits
        ];
        foreach ($refused as $pin => $field) {
            $e = $this->catchApiError(fn () => $this->savePin($login, ['user_pin' => (string) $pin, 'user_pin_confirmation' => (string) $pin]));
            $this->assertArrayHasKey($field, $e->fields(), "PIN {$pin} should be refused");
        }

        $e = $this->catchApiError(fn () => $this->savePin($login, ['user_pin' => self::PIN, 'user_pin_confirmation' => '4827']));
        $this->assertArrayHasKey('user_pin_confirmation', $e->fields());
    }

    public function testUnknownNumberGetsTheSameAnswerAsAWrongPin(): void
    {
        $e = $this->catchApiError(fn () => $this->customer_auth->logInWithPin($this->pinLoginForm(self::PIN), self::IP_ADDRESS));

        $this->assertSame('PIN_INVALID', $e->errorCode());
        $this->assertSame('Namba ya simu au PIN si sahihi.', $e->getMessage());
    }

    public function testPinIsStoredOnlyAsAHash(): void
    {
        $this->registerWithPin();

        $pin_hash = (string) $this->db->fetchValue('SELECT user_pin_hash FROM users');
        $this->assertStringNotContainsString(self::PIN, $pin_hash);
        $this->assertStringStartsWith('$2y$', $pin_hash);   // bcrypt
    }

    public function testStaffCanForceAPinResetWhichLogsTheCustomerOut(): void
    {
        $device  = $this->registerWithPin();
        $user_id = $device['user']['user_id'];

        (new CustomerPin($this->db))->forcePinReset($user_id, 1);

        $this->assertNull($this->auth_token->findUserIdByToken($device['auth_token']));
        $this->assertSame('pin_locked', $this->customer_auth->startLogin(['user_phone' => self::PHONE], self::IP_ADDRESS)['next_step']);
        $this->assertSame(1, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE audit_log_action = 'customer.pin_reset_forced' AND audit_log_entity_id = :user_id",
            ['user_id' => $user_id]
        ));
    }

    public function testWebsiteLoginsOlderThanAPinChangeStopWorking(): void
    {
        $user_id    = $this->registerWithPin()['user']['user_id'];
        $user_model = new User($this->db);
        $before     = gmdate('Y-m-d H:i:s', time() - 60);

        $this->assertTrue($user_model->isActiveUser($user_id, $before));
        $user_model->revokeWebsiteSessions($user_id);
        $this->assertFalse($user_model->isActiveUser($user_id, $before));
        $this->assertTrue($user_model->isActiveUser($user_id, gmdate('Y-m-d H:i:s')));
    }

    // ------------------------------------------------------------------ helpers

    /** SMS-code login + first PIN. Returns the login (user + auth_token). */
    private function registerWithPin(): array
    {
        $login = $this->otpLogin();
        $this->savePin($login, ['user_pin' => self::PIN, 'user_pin_confirmation' => self::PIN]);
        return $login;
    }

    private function otpLogin(): array
    {
        $code = $this->customer_auth->requestLoginCode(['user_phone' => self::PHONE], self::IP_ADDRESS)['debug_otp_code'];
        return $this->customer_auth->verifyLoginCode($this->otpLoginForm($code), self::IP_ADDRESS);
    }

    private function savePin(array $login, array $input): array
    {
        return $this->customer_auth->savePin($login['user']['user_id'], $login['auth_token'], $input);
    }

    private function otpLoginForm(string $code): array
    {
        return ['user_phone' => self::PHONE, 'otp_code' => $code, 'client' => 'app', 'auth_token_platform' => 'android'];
    }

    private function pinLoginForm(string $pin): array
    {
        return ['user_phone' => self::PHONE, 'user_pin' => $pin, 'client' => 'app', 'auth_token_platform' => 'android'];
    }

    private function catchApiError(callable $action): ApiException
    {
        try {
            $action();
        } catch (ApiException $e) {
            return $e;
        }
        $this->fail('Expected an ApiException');
    }
}
