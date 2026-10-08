<?php

/**
 * CHIMBO staff accounts: login check, lockout after wrong passwords, and role permissions.
 *
 * How to use it:
 *   $admin_model = new Admin(Database::instance());
 *   $admin = $admin_model->login($_POST);              // throws ApiException with a message if it fails
 *   Admin::can($admin, 'products.manage');             // true / false
 */
class Admin
{
    /**
     * What each role may do. 'super_admin' may do everything.
     * Pages and actions check these with Admin::can() or AdminSession::requireLogin('permission').
     */
    public const ROLE_PERMISSIONS = [
        'super_admin' => ['*'],
        'catalog'     => ['dashboard.view', 'products.manage', 'categories.manage', 'sellers.manage', 'banners.manage', 'inventory.manage'],
        'operations'  => ['dashboard.view', 'orders.view', 'orders.manage', 'delivery.manage', 'customers.view', 'customers.manage', 'payments.confirm_cash'],
        'finance'     => ['dashboard.view', 'payments.manage', 'reports.view', 'orders.view', 'customers.view'],   // payment_methods.manage = super admin only
    ];

    public const ROLE_NAMES = [
        'super_admin' => 'Super Admin',
        'catalog'     => 'Catalog Manager',
        'operations'  => 'Operations',
        'finance'     => 'Finance',
    ];

    private const MAX_FAILED_LOGINS = 5;    // wrong passwords before the account is locked
    private const LOCK_MINUTES      = 15;   // how long the lock lasts

    // A real bcrypt hash of a random password nobody knows (used for unknown emails, see login())
    private const DUMMY_PASSWORD_HASH = '$2y$10$scCQlI0JkoJMVc0IHOMSMOA8C6P7F2SZvd5ESAxnOMfpNwIzM6mi2';

    // Columns that are safe to keep in memory (never the password hash)
    private const PUBLIC_COLUMNS = 'admin_id, admin_full_name, admin_email, admin_role, admin_status, admin_last_login_at';

    public function __construct(private Database $db)
    {
    }

    /**
     * Checks email + password. Returns the admin (without the password hash).
     * Throws ApiException with a message for the login form when it fails.
     */
    public function login(array $input): array
    {
        $data = Validator::validate($input, [
            'admin_email'    => 'required|email|max:150',
            'admin_password' => 'required|string|max:200',
        ]);

        $admin = $this->db->fetchOne(
            'SELECT admin_id, admin_password_hash, admin_status, admin_failed_logins,
                    admin_locked_until > UTC_TIMESTAMP() AS is_locked
             FROM admins
             WHERE admin_email = :admin_email',
            ['admin_email' => $data['admin_email']]
        );

        // Unknown email: still run password_verify so the answer takes the same time as a real
        // account (otherwise an attacker could tell which emails exist by measuring the delay)
        if ($admin === null) {
            password_verify($data['admin_password'], self::DUMMY_PASSWORD_HASH);
            throw $this->wrongLoginException();
        }

        if ($admin['is_locked']) {
            throw ApiException::tooManyRequests(
                'This account is locked for ' . self::LOCK_MINUTES . ' minutes after too many wrong passwords.'
            );
        }

        if (!password_verify($data['admin_password'], $admin['admin_password_hash'])) {
            $this->recordFailedLogin((int) $admin['admin_id'], (int) $admin['admin_failed_logins'] + 1);
            throw $this->wrongLoginException();
        }

        if ($admin['admin_status'] !== 'active') {
            throw ApiException::forbidden('This account is disabled. Contact the super admin.');
        }

        $this->recordSuccessfulLogin((int) $admin['admin_id'], $data['admin_password'], $admin['admin_password_hash']);

        return $this->getActiveAdminById((int) $admin['admin_id']);
    }

    /** The admin row (without the password hash), or null if missing or disabled. */
    public function getActiveAdminById(int $admin_id): ?array
    {
        return $this->db->fetchOne(
            'SELECT ' . self::PUBLIC_COLUMNS . " FROM admins WHERE admin_id = :admin_id AND admin_status = 'active'",
            ['admin_id' => $admin_id]
        );
    }

    /** True when the admin's role includes this permission. */
    public static function can(array $admin, string $permission): bool
    {
        $permissions = self::ROLE_PERMISSIONS[$admin['admin_role']] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    private function wrongLoginException(): ApiException
    {
        // The same message for "unknown email" and "wrong password", so emails cannot be guessed
        return ApiException::unauthenticated('Wrong email or password.');
    }

    /** Counts a wrong password; locks the account when the limit is reached. */
    private function recordFailedLogin(int $admin_id, int $failed_logins): void
    {
        $lock_account = $failed_logins >= self::MAX_FAILED_LOGINS;

        $this->db->execute(
            'UPDATE admins
             SET admin_failed_logins = :failed_logins,
                 admin_locked_until  = IF(:lock_account, UTC_TIMESTAMP() + INTERVAL ' . self::LOCK_MINUTES . ' MINUTE, NULL)
             WHERE admin_id = :admin_id',
            [
                'failed_logins' => $lock_account ? 0 : $failed_logins,
                'lock_account'  => (int) $lock_account,
                'admin_id'      => $admin_id,
            ]
        );

        if ($lock_account) {
            (new AuditLog($this->db))->record('system', null, 'admin.locked', 'admin', $admin_id);
        }
    }

    /** Resets the failure counter, saves the login time and upgrades the password hash if needed. */
    private function recordSuccessfulLogin(int $admin_id, string $password, string $password_hash): void
    {
        $this->db->execute(
            'UPDATE admins
             SET admin_failed_logins = 0, admin_locked_until = NULL, admin_last_login_at = UTC_TIMESTAMP()
             WHERE admin_id = :admin_id',
            ['admin_id' => $admin_id]
        );

        // PHP's recommended hashing gets stronger over time; re-hash old passwords at login
        if (password_needs_rehash($password_hash, PASSWORD_DEFAULT)) {
            $this->db->execute(
                'UPDATE admins SET admin_password_hash = :password_hash WHERE admin_id = :admin_id',
                ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'admin_id' => $admin_id]
            );
        }

        (new AuditLog($this->db))->record('admin', $admin_id, 'admin.login', 'admin', $admin_id);
    }
}
