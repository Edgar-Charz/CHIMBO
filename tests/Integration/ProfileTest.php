<?php

use PHPUnit\Framework\TestCase;

/** The customer's own account: edit profile, edit business, delete account. */
final class ProfileTest extends TestCase
{
    private Database $db;
    private User $user_model;
    private int $user_id;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        $this->db->execute('DELETE FROM audit_logs');

        $this->user_model = new User($this->db);
        $this->user_id    = $this->user_model->findOrCreateUserIdByPhone('+255712000111');
    }

    public function testUpdateAccountChangesOnlyTheSentFields(): void
    {
        $this->user_model->updateAccount($this->user_id, ['user_full_name' => 'Joyce Joseph']);
        $profile = $this->user_model->updateAccount($this->user_id, ['user_locale' => 'en']);

        $this->assertSame('Joyce Joseph', $profile['user_full_name']); // kept from the first call
        $this->assertSame('en', $profile['user_locale']);
    }

    public function testEmailCannotBelongToTwoAccounts(): void
    {
        $other_user_id = $this->user_model->findOrCreateUserIdByPhone('+255754000222');
        $this->user_model->updateAccount($other_user_id, ['user_email' => 'shop@example.com']);

        $this->expectExceptionObject(ApiException::validation(['user_email' => 'Barua pepe hii inatumiwa na akaunti nyingine.']));
        $this->user_model->updateAccount($this->user_id, ['user_email' => 'shop@example.com']);
    }

    public function testUpdateBusinessSavesRegionAndDistrict(): void
    {
        $region_id   = (int) $this->db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Mwanza'");
        $district_id = (int) $this->db->fetchValue("SELECT district_id FROM districts WHERE district_name = 'Ilemela'");

        $profile = $this->user_model->updateBusiness($this->user_id, [
            'business_name' => 'Asha Cosmetics',
            'region_id'     => $region_id,
            'district_id'   => $district_id,
        ]);

        $this->assertSame('Ilemela', $profile['business']['district_name']);
    }

    public function testDeleteNeedsConfirmation(): void
    {
        $this->expectException(ApiException::class);
        $this->user_model->deleteAccount($this->user_id, ['confirm' => false]);
    }

    public function testDeleteRemovesPersonalDataEndsLoginsAndFreesThePhone(): void
    {
        $auth_token = new AuthToken($this->db);
        $token = $auth_token->createToken($this->user_id, null, 'android')['auth_token'];
        $this->user_model->updateAccount($this->user_id, ['user_full_name' => 'Joyce Joseph']);

        $this->user_model->deleteAccount($this->user_id, ['confirm' => true]);

        $deleted_user = $this->db->fetchOne('SELECT user_phone, user_full_name, user_status FROM users WHERE user_id = :id', ['id' => $this->user_id]);
        $this->assertSame("deleted-{$this->user_id}", $deleted_user['user_phone']);
        $this->assertNull($deleted_user['user_full_name']);
        $this->assertSame('deleted', $deleted_user['user_status']);
        $this->assertNull($auth_token->findUserIdByToken($token));

        // The same phone number can register again, as a brand-new account
        $new_user_id = $this->user_model->findOrCreateUserIdByPhone('+255712000111');
        $this->assertNotSame($this->user_id, $new_user_id);
    }
}
