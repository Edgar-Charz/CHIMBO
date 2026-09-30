<?php

use PHPUnit\Framework\TestCase;

/**
 * Customer login (phone + OTP) against the test database.
 * Each test proves one rule from the blueprint (§15 Security).
 */
final class CustomerLoginTest extends TestCase
{
    private const PHONE      = '+255712000111';
    private const IP_ADDRESS = '127.0.0.1';

    private Database $db;
    private CustomerAuth $customer_auth;
    private Otp $otp;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        Settings::clearCache();
        Env::set('APP_ENV', 'local');        // most tests read the code from the response
        Env::set('OTP_SHOW_CODE', 'true');

        $this->customer_auth = new CustomerAuth($this->db);
        $this->otp           = new Otp($this->db);
    }

    protected function tearDown(): void
    {
        Env::set('APP_ENV', 'testing');
        Env::set('OTP_SHOW_CODE', 'false');
    }

    public function testNewCustomerRegistersAndCompletesProfile(): void
    {
        $code = $this->customer_auth->requestLoginCode(['user_phone' => '0712 000 111'], self::IP_ADDRESS)['debug_otp_code'];

        $login = $this->customer_auth->verifyLoginCode(
            ['user_phone' => '0712000111', 'otp_code' => $code, 'client' => 'app', 'auth_token_platform' => 'android'],
            self::IP_ADDRESS
        );

        $this->assertSame(self::PHONE, $login['user']['user_phone']);
        $this->assertFalse($login['user']['is_profile_complete']);
        $this->assertSame(64, strlen($login['auth_token']));

        $profile = (new User($this->db))->completeProfile($login['user']['user_id'], [
            'user_full_name' => 'Joyce Joseph',
            'business_name'  => 'Duka la Joyce',
            'region_id'      => $this->regionId('Dar es Salaam'),
        ]);

        $this->assertTrue($profile['is_profile_complete']);
        $this->assertSame('Dar es Salaam', $profile['business']['region_name']);
    }

    public function testCodeIsShownOnStagingWhenShowCodeModeIsOn(): void
    {
        Env::set('APP_ENV', 'staging');

        $result = $this->customer_auth->requestLoginCode(['user_phone' => self::PHONE], self::IP_ADDRESS);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $result['debug_otp_code']);
    }

    public function testCodeIsNeverShownInProductionEvenIfShowCodeModeIsOn(): void
    {
        Env::set('APP_ENV', 'production');

        $result = $this->customer_auth->requestLoginCode(['user_phone' => self::PHONE], self::IP_ADDRESS);

        $this->assertArrayNotHasKey('debug_otp_code', $result);
    }

    public function testCodeIsNotShownWhenShowCodeModeIsOff(): void
    {
        Env::set('OTP_SHOW_CODE', 'false');

        $result = $this->customer_auth->requestLoginCode(['user_phone' => self::PHONE], self::IP_ADDRESS);

        $this->assertArrayNotHasKey('debug_otp_code', $result);
    }

    public function testCodeIsNeverStoredInPlainText(): void
    {
        $code = $this->otp->createLoginCode(self::PHONE, self::IP_ADDRESS);
        $this->customer_auth->requestLoginCode(['user_phone' => '0754000222'], self::IP_ADDRESS);

        $stored_hash = $this->db->fetchValue('SELECT otp_code_hash FROM otp_codes WHERE otp_phone = :phone', ['phone' => self::PHONE]);
        $this->assertNotSame($code, $stored_hash);
        $this->assertNotSame(hash('sha256', $code), $stored_hash);

        $sms_message = $this->db->fetchValue('SELECT sms_message FROM sms_outbox LIMIT 1');
        $this->assertStringContainsString('******', $sms_message);
    }

    public function testCodeWorksOnlyOnce(): void
    {
        $code = $this->otp->createLoginCode(self::PHONE, self::IP_ADDRESS);
        $this->otp->verifyLoginCode(self::PHONE, $code);

        $this->assertApiError('OTP_EXPIRED', fn () => $this->otp->verifyLoginCode(self::PHONE, $code));
    }

    public function testWrongCodeIsRejectedAndBlockedAfterMaxAttempts(): void
    {
        $code = $this->otp->createLoginCode(self::PHONE, self::IP_ADDRESS);
        $wrong_code = $code === '000000' ? '111111' : '000000';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertApiError('OTP_INVALID', fn () => $this->otp->verifyLoginCode(self::PHONE, $wrong_code));
        }

        // Even the right code no longer works after 5 wrong guesses
        $this->assertApiError('OTP_TOO_MANY_ATTEMPTS', fn () => $this->otp->verifyLoginCode(self::PHONE, $code));
    }

    public function testExpiredCodeIsRejected(): void
    {
        $code = $this->otp->createLoginCode(self::PHONE, self::IP_ADDRESS);
        $this->db->execute('UPDATE otp_codes SET otp_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND');

        $this->assertApiError('OTP_EXPIRED', fn () => $this->otp->verifyLoginCode(self::PHONE, $code));
    }

    public function testResendMustWait(): void
    {
        $this->otp->createLoginCode(self::PHONE, self::IP_ADDRESS);

        $this->assertApiError('OTP_RESEND_TOO_SOON', fn () => $this->otp->createLoginCode(self::PHONE, self::IP_ADDRESS));
    }

    public function testNewCodeCancelsTheOldOne(): void
    {
        $old_code = $this->otp->createLoginCode(self::PHONE, self::IP_ADDRESS);
        $this->db->execute('UPDATE otp_codes SET created_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE'); // pass the resend wait
        $new_code = $this->otp->createLoginCode(self::PHONE, self::IP_ADDRESS);

        if ($old_code !== $new_code) {
            $this->assertApiError('OTP_INVALID', fn () => $this->otp->verifyLoginCode(self::PHONE, $old_code));
        }
        $this->otp->verifyLoginCode(self::PHONE, $new_code);
        $this->addToAssertionCount(1); // reaching this line means the new code worked
    }

    public function testTokenStopsWorkingAfterLogout(): void
    {
        $user_id = (new User($this->db))->findOrCreateUserIdByPhone(self::PHONE);
        $auth_token = new AuthToken($this->db);
        $token = $auth_token->createToken($user_id, 'Test phone', 'android')['auth_token'];

        $this->assertSame($user_id, $auth_token->findUserIdByToken($token));

        $this->customer_auth->logOut($token);

        $this->assertNull($auth_token->findUserIdByToken($token));
    }

    public function testDistrictMustBelongToTheRegion(): void
    {
        $user_id = (new User($this->db))->findOrCreateUserIdByPhone(self::PHONE);
        $district_in_arusha = (int) $this->db->fetchValue("SELECT district_id FROM districts WHERE district_name = 'Karatu'");

        $this->assertApiError('VALIDATION_ERROR', fn () => (new User($this->db))->completeProfile($user_id, [
            'user_full_name' => 'Joyce Joseph',
            'region_id'      => $this->regionId('Dar es Salaam'),
            'district_id'    => $district_in_arusha,
        ]));
    }

    public function testSuspendedCustomerCannotLogIn(): void
    {
        $user_model = new User($this->db);
        $user_id = $user_model->findOrCreateUserIdByPhone(self::PHONE);
        $this->db->execute("UPDATE users SET user_status = 'suspended' WHERE user_id = :user_id", ['user_id' => $user_id]);

        $this->assertApiError('FORBIDDEN', fn () => $user_model->findOrCreateUserIdByPhone(self::PHONE));
    }

    /** Runs $action and checks that it fails with this API error code. */
    private function assertApiError(string $expected_code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected the error {$expected_code}");
        } catch (ApiException $e) {
            $this->assertSame($expected_code, $e->errorCode());
        }
    }

    private function regionId(string $region_name): int
    {
        return (int) $this->db->fetchValue('SELECT region_id FROM regions WHERE region_name = :region_name', ['region_name' => $region_name]);
    }
}
