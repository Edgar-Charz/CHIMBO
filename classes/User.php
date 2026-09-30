<?php

/**
 * Customers (business owners) and their business profile.
 *
 * How to use it:
 *   $user_model = new User(Database::instance());
 *   $user_id = $user_model->findOrCreateUserIdByPhone('+255712345678');   // after the OTP is correct
 *   $user_model->completeProfile($user_id, $input);                      // registration step 3
 *   $user_model->getProfile($user_id);                                    // what the apps show in "Wasifu"
 */
class User
{
    // Rules shared by registration step 3 and "Taarifa za Biashara"
    private const BUSINESS_RULES = [
        'business_name' => 'nullable|string|max:120',
        'region_id'     => 'required|int|min:1',
        'district_id'   => 'nullable|int|min:1',
    ]; 

    public function __construct(private Database $db)
    {
    }

    /**
     * Returns the id of the customer with this phone, creating the account on the first login.
     * Throws 403 if the account is suspended.
     */
    public function findOrCreateUserIdByPhone(string $phone): int
    {
        $this->db->execute(
            'INSERT INTO users (user_phone, user_phone_verified_at, user_last_login_at)
             VALUES (:user_phone, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                 user_last_login_at     = UTC_TIMESTAMP(),
                 user_phone_verified_at = COALESCE(user_phone_verified_at, UTC_TIMESTAMP())',
            ['user_phone' => $phone]
        );

        $user = $this->db->fetchOne(
            'SELECT user_id, user_status FROM users WHERE user_phone = :user_phone',
            ['user_phone' => $phone]
        );

        if ($user['user_status'] !== 'active') {
            throw ApiException::forbidden('Akaunti yako imesimamishwa. Wasiliana na CHIMBO kwa msaada.');
        }

        return (int) $user['user_id'];
    }

    /** True when the account exists and is active (used on every logged-in request). */
    public function isActiveUser(int $user_id): bool
    {
        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM users WHERE user_id = :user_id AND user_status = 'active'",
            ['user_id' => $user_id]
        );
    }

    /** The customer with their business profile, in the shape the apps use. */
    public function getProfile(int $user_id): array
    {
        $row = $this->db->fetchOne(
            'SELECT u.user_id, u.user_phone, u.user_full_name, u.user_email, u.user_avatar_path, u.user_locale, u.created_at,
                    b.business_profile_id, b.business_name, b.region_id, r.region_name, b.district_id, d.district_name,
                    b.business_verification_status
             FROM users u
             LEFT JOIN business_profiles b ON b.user_id = u.user_id
             LEFT JOIN regions r ON r.region_id = b.region_id
             LEFT JOIN districts d ON d.district_id = b.district_id
             WHERE u.user_id = :user_id',
            ['user_id' => $user_id]
        );

        if ($row === null) {
            throw ApiException::notFound('Akaunti haikupatikana.');
        }

        $has_business = $row['business_profile_id'] !== null;

        return [
            'user_id'             => (int) $row['user_id'],
            'user_phone'          => $row['user_phone'],
            'user_full_name'      => $row['user_full_name'],
            'user_email'          => $row['user_email'],
            'user_avatar_url'     => $row['user_avatar_path'] ? url($row['user_avatar_path']) : null,
            'user_locale'         => $row['user_locale'],
            'created_at'          => isoDate($row['created_at']),
            'business'            => $has_business ? [
                'business_name'                => $row['business_name'],
                'region_id'                    => (int) $row['region_id'],
                'region_name'                  => $row['region_name'],
                'district_id'                  => $row['district_id'] === null ? null : (int) $row['district_id'],
                'district_name'                => $row['district_name'],
                'business_verification_status' => $row['business_verification_status'],
            ] : null,
            // Registration step 3 is done when the name and the business region are saved
            'is_profile_complete' => $row['user_full_name'] !== null && $has_business,
        ];
    }

    /**
     * Registration step 3 ("Tuambie Kuhusu Biashara Yako"): saves the name and the business.
     * Also used later to edit them. Returns the updated profile.
     */
    public function completeProfile(int $user_id, array $input): array
    {
        $data = Validator::validate($input, ['user_full_name' => 'required|string|min:2|max:100'] + self::BUSINESS_RULES);
        (new Region($this->db))->checkLocation($data['region_id'], $data['district_id'] ?? null);

        $this->db->transaction(function () use ($user_id, $data) {
            $this->db->execute(
                'UPDATE users SET user_full_name = :user_full_name WHERE user_id = :user_id',
                ['user_full_name' => $data['user_full_name'], 'user_id' => $user_id]
            );
            $this->saveBusiness($user_id, $data);
        });

        return $this->getProfile($user_id);
    }

    /**
     * "Hariri Wasifu" (PATCH /me): changes only the fields that were sent — name, email, language.
     * Returns the updated profile.
     */
    public function updateAccount(int $user_id, array $input): array
    {
        $data = Validator::validate($input, [
            'user_full_name' => 'string|min:2|max:100',
            'user_email'     => 'nullable|email|max:150',   // sent empty → email removed
            'user_locale'    => 'in:sw,en',
        ]);

        if ($data === []) {
            return $this->getProfile($user_id); // nothing to change
        }
        if (!empty($data['user_email'])) {
            $this->checkEmailIsFree($user_id, $data['user_email']);
        }

        // "user_full_name = :user_full_name, …" for the sent fields only.
        // The column names come from the rules above, never from the request itself.
        $assignments = implode(', ', array_map(fn (string $column) => "{$column} = :{$column}", array_keys($data)));
        $this->db->execute("UPDATE users SET {$assignments} WHERE user_id = :user_id", $data + ['user_id' => $user_id]);

        return $this->getProfile($user_id);
    }

    /** "Taarifa za Biashara" (PATCH /me/business): shop name, region, district. Returns the updated profile. */
    public function updateBusiness(int $user_id, array $input): array
    {
        $data = Validator::validate($input, self::BUSINESS_RULES);
        (new Region($this->db))->checkLocation($data['region_id'], $data['district_id'] ?? null);

        $this->saveBusiness($user_id, $data);

        return $this->getProfile($user_id);
    }

    /**
     * Deletes the account (DELETE /me, required by Google Play and the App Store).
     * Personal details are removed and every login ends. The row itself stays, so past orders
     * (kept for the business records) still point to it; the phone becomes "deleted-<id>", so the real number can register again.
     */
    public function deleteAccount(int $user_id, array $input): void
    {
        $data = Validator::validate($input, ['confirm' => 'required|bool']);
        if ($data['confirm'] !== true) {
            throw ApiException::validation(['confirm' => 'Thibitisha kwamba unataka kufuta akaunti.']);
        }

        $this->db->transaction(function () use ($user_id) {
            $this->db->execute(
                "UPDATE users
                 SET user_phone = CONCAT('deleted-', user_id), user_full_name = NULL, user_email = NULL,
                     user_avatar_path = NULL, user_status = 'deleted', deleted_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id",
                ['user_id' => $user_id]
            );
            foreach (['business_profiles', 'wishlist_items', 'cart_items', 'addresses', 'notifications'] as $personal_table) {
                $this->db->execute("DELETE FROM {$personal_table} WHERE user_id = :user_id", ['user_id' => $user_id]);
            }

            (new AuthToken($this->db))->revokeAllTokensForUser($user_id);
            (new AuditLog($this->db))->record('customer', $user_id, 'user.deleted', 'user', $user_id);
        });
    }

    /** One business profile per user: inserted the first time, updated after that. */
    private function saveBusiness(int $user_id, array $data): void
    {
        $this->db->execute(
            'INSERT INTO business_profiles (user_id, business_name, region_id, district_id)
             VALUES (:user_id, :business_name, :region_id, :district_id)
             ON DUPLICATE KEY UPDATE
                 business_name = VALUES(business_name),
                 region_id     = VALUES(region_id),
                 district_id   = VALUES(district_id)',
            [
                'user_id'       => $user_id,
                'business_name' => $data['business_name'] ?? null,
                'region_id'     => $data['region_id'],
                'district_id'   => $data['district_id'] ?? null,
            ]
        );
    }

    /** Two accounts cannot share one email. */
    private function checkEmailIsFree(int $user_id, string $email): void
    {
        $used_by_other_account = $this->db->fetchValue(
            'SELECT 1 FROM users WHERE user_email = :user_email AND user_id <> :user_id',
            ['user_email' => $email, 'user_id' => $user_id]
        );
        if ($used_by_other_account) {
            throw ApiException::validation(['user_email' => 'Barua pepe hii inatumiwa na akaunti nyingine.']);
        }
    }
}
