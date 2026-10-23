<?php

use PHPUnit\Framework\TestCase;

/** Staff actions on a customer account: business verification, suspend / reactivate. */
final class CustomerAdminTest extends TestCase
{
    private const ADMIN_ID = 1;

    private Database $db;
    private Customer $customer_model;
    private int $user_id;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        $this->db->execute(
            "INSERT IGNORE INTO admins (admin_id, admin_full_name, admin_email, admin_password_hash, admin_role)
             VALUES (1, 'Test Admin', 'test-admin@chimbo.test', 'x', 'super_admin')"
        );
        $this->customer_model = new Customer($this->db);
        $user_model    = new User($this->db);
        $this->user_id = $user_model->findOrCreateUserIdByPhone('+255712000111');
        $user_model->completeProfile($this->user_id, [
            'user_full_name' => 'Joyce Joseph',
            'business_name'  => 'Duka la Joyce',
            'region_id'      => (int) $this->db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'"),
        ]);
    }

    public function testVerifyingShowsTheBadgeAndTellsTheCustomer(): void
    {
        $this->customer_model->setBusinessVerification($this->user_id, ['business_verification_status' => 'verified'], self::ADMIN_ID);

        $customer = $this->customer_model->getCustomerById($this->user_id);
        $this->assertSame('verified', $customer['business_verification_status']);
        $this->assertNotNull($customer['business_verified_at']);
        $this->assertSame('Test Admin', $customer['verified_by_admin_name']);
        $this->assertSame('verified', (new User($this->db))->getProfile($this->user_id)['business']['business_verification_status']);
        $this->assertSame('Biashara imethibitishwa', $this->latestNotificationTitle());
        $notification_data = json_decode((string) $this->db->fetchValue(
            'SELECT notification_data FROM notifications WHERE user_id = :user_id ORDER BY notification_id DESC LIMIT 1',
            ['user_id' => $this->user_id]
        ), true);
        $this->assertSame('Business verified', $notification_data['translations']['en']['title']);
        $this->assertSame('Biashara imethibitishwa', $notification_data['translations']['sw']['title']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE audit_log_action = 'customer.business_verification' AND audit_log_entity_id = {$this->user_id}"));
    }

    public function testRejectingNeedsAReasonThatTheCustomerSees(): void
    {
        try {
            $this->customer_model->setBusinessVerification($this->user_id, ['business_verification_status' => 'rejected'], self::ADMIN_ID);
            $this->fail('Expected a validation error');
        } catch (ApiException $e) {
            $this->assertArrayHasKey('business_verification_note', $e->fields());
        }

        $this->customer_model->setBusinessVerification($this->user_id, [
            'business_verification_status' => 'rejected',
            'business_verification_note'   => 'Jina la duka halilingani na leseni.',
        ], self::ADMIN_ID);

        $this->assertSame('rejected', $this->customer_model->getCustomerById($this->user_id)['business_verification_status']);
        $this->assertStringContainsString('Jina la duka halilingani na leseni.', (string) $this->db->fetchValue(
            'SELECT notification_body FROM notifications WHERE user_id = :user_id ORDER BY notification_id DESC LIMIT 1',
            ['user_id' => $this->user_id]
        ));
    }

    public function testSettingBackToNotVerifiedClearsWhoVerified(): void
    {
        $this->customer_model->setBusinessVerification($this->user_id, ['business_verification_status' => 'verified'], self::ADMIN_ID);
        $this->customer_model->setBusinessVerification($this->user_id, ['business_verification_status' => 'unverified'], self::ADMIN_ID);

        $customer = $this->customer_model->getCustomerById($this->user_id);
        $this->assertSame(['unverified', null, null], [$customer['business_verification_status'], $customer['business_verified_at'], $customer['verified_by_admin_name']]);
    }

    public function testCustomerWithoutBusinessDetailsCannotBeVerified(): void
    {
        $new_user_id = (new User($this->db))->findOrCreateUserIdByPhone('+255754000222');

        try {
            $this->customer_model->setBusinessVerification($new_user_id, ['business_verification_status' => 'verified'], self::ADMIN_ID);
            $this->fail('Expected NO_BUSINESS');
        } catch (ApiException $e) {
            $this->assertSame('NO_BUSINESS', $e->errorCode());
        }
    }

    public function testSuspendingLogsOutEverywhereAndRefusesLogin(): void
    {
        $token = (new AuthToken($this->db))->createToken($this->user_id, 'Tecno Spark 10', 'android')['auth_token'];

        $this->customer_model->setCustomerStatus($this->user_id, ['user_status' => 'suspended', 'user_status_reason' => 'Oda za uongo mara tatu.'], self::ADMIN_ID);

        $this->assertNull((new AuthToken($this->db))->findUserIdByToken($token));
        $this->assertFalse((new User($this->db))->isActiveUser($this->user_id, gmdate('Y-m-d H:i:s', time() - 60)));
        try {
            (new User($this->db))->findOrCreateUserIdByPhone('+255712000111');   // what every login does
            $this->fail('Expected FORBIDDEN');
        } catch (ApiException $e) {
            $this->assertSame('FORBIDDEN', $e->errorCode());
        }
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE audit_log_action = 'customer.suspended' AND audit_log_entity_id = {$this->user_id}"));
    }

    public function testReactivatingAllowsLoginAgain(): void
    {
        $this->customer_model->setCustomerStatus($this->user_id, ['user_status' => 'suspended', 'user_status_reason' => 'Oda za uongo mara tatu.'], self::ADMIN_ID);
        $this->customer_model->setCustomerStatus($this->user_id, ['user_status' => 'active'], self::ADMIN_ID);

        $this->assertSame($this->user_id, (new User($this->db))->findOrCreateUserIdByPhone('+255712000111'));
        $this->assertSame('active', $this->customer_model->getCustomerById($this->user_id)['user_status']);
    }

    public function testSuspendingNeedsAReasonAndDeletedAccountsCannotChange(): void
    {
        try {
            $this->customer_model->setCustomerStatus($this->user_id, ['user_status' => 'suspended'], self::ADMIN_ID);
            $this->fail('Expected a validation error');
        } catch (ApiException $e) {
            $this->assertArrayHasKey('user_status_reason', $e->fields());
        }

        $this->db->execute("UPDATE users SET user_status = 'deleted' WHERE user_id = {$this->user_id}");
        try {
            $this->customer_model->setCustomerStatus($this->user_id, ['user_status' => 'active'], self::ADMIN_ID);
            $this->fail('Expected CUSTOMER_DELETED');
        } catch (ApiException $e) {
            $this->assertSame('CUSTOMER_DELETED', $e->errorCode());
        }
    }

    private function latestNotificationTitle(): string
    {
        return (string) $this->db->fetchValue(
            'SELECT notification_title FROM notifications WHERE user_id = :user_id ORDER BY notification_id DESC LIMIT 1',
            ['user_id' => $this->user_id]
        );
    }
}
