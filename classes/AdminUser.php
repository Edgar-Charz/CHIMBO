<?php

/**
 * Managing CHIMBO staff accounts (the "Admin users" page, super admin only).
 * Logging in and permissions live in the Admin class.
 *
 * Safety rules:
 * - passwords are at least 10 characters and stored only as password_hash()
 * - nobody can disable themselves or take away their own super admin role
 * - there is always at least one active super admin
 * - every change is written to the audit log (never the password)
 *
 * How to use it:
 *   $admin_user_model = new AdminUser(Database::instance());
 *   $admins   = $admin_user_model->listAdmins();
 *   $admin_id = $admin_user_model->createAdmin($_POST, $current_admin['admin_id']);
 */
class AdminUser
{
    private const MIN_PASSWORD_LENGTH = 10;

    // Columns that are safe to show (never the password hash)
    private const PUBLIC_COLUMNS = 'admin_id, admin_full_name, admin_email, admin_role, admin_status,
                                    admin_locked_until > UTC_TIMESTAMP() AS admin_is_locked, admin_last_login_at, created_at';

    public function __construct(private Database $db)
    {
    }

    /** Every staff account, active ones first, then by name. */
    public function listAdmins(): array
    {
        return $this->db->fetchAll(
            'SELECT ' . self::PUBLIC_COLUMNS . " FROM admins ORDER BY admin_status = 'active' DESC, admin_full_name"
        );
    }

    /** One account for the edit form, or 404. */
    public function getAdminById(int $admin_id): array
    {
        $admin = $this->db->fetchOne('SELECT ' . self::PUBLIC_COLUMNS . ' FROM admins WHERE admin_id = :admin_id', ['admin_id' => $admin_id]);
        if ($admin === null) {
            throw ApiException::notFound('Staff account not found.');
        }
        return $admin;
    }

    /** Form fields: admin_full_name, admin_email, admin_role, admin_password, admin_password_confirmation. Returns the new id. */
    public function createAdmin(array $input, int $current_admin_id): int
    {
        $data = Validator::validate($input, $this->detailRules() + $this->passwordRules());
        $this->checkEmailIsFree($data['admin_email'], null);
        $this->checkPasswordConfirmation($data);

        $admin_id = $this->db->insert(
            'INSERT INTO admins (admin_full_name, admin_email, admin_password_hash, admin_role)
             VALUES (:admin_full_name, :admin_email, :password_hash, :admin_role)',
            [
                'admin_full_name' => $data['admin_full_name'],
                'admin_email'     => strtolower($data['admin_email']),
                'password_hash'   => password_hash($data['admin_password'], PASSWORD_DEFAULT),
                'admin_role'      => $data['admin_role'],
            ]
        );

        (new AuditLog($this->db))->record('admin', $current_admin_id, 'admin.created', 'admin', $admin_id, null, [
            'admin_full_name' => $data['admin_full_name'],
            'admin_email'     => strtolower($data['admin_email']),
            'admin_role'      => $data['admin_role'],
        ]);
        return $admin_id;
    }

    /** Form fields: admin_full_name, admin_email, admin_role, admin_status (active / disabled). */
    public function updateAdmin(int $admin_id, array $input, int $current_admin_id): void
    {
        $data = Validator::validate($input, $this->detailRules() + ['admin_status' => 'required|in:active,disabled']);
        $old  = $this->getAdminById($admin_id);
        $this->checkEmailIsFree($data['admin_email'], $admin_id);

        if ($admin_id === $current_admin_id && $data['admin_status'] !== 'active') {
            throw ApiException::validation(['admin_status' => 'You cannot disable your own account.']);
        }
        if ($admin_id === $current_admin_id && $old['admin_role'] === 'super_admin' && $data['admin_role'] !== 'super_admin') {
            throw ApiException::validation(['admin_role' => 'You cannot remove your own super admin role.']);
        }

        $new = [
            'admin_full_name' => $data['admin_full_name'],
            'admin_email'     => strtolower($data['admin_email']),
            'admin_role'      => $data['admin_role'],
            'admin_status'    => $data['admin_status'],
        ];

        $this->db->transaction(function (Database $db) use ($admin_id, $new, $old, $current_admin_id) {
            $db->execute(
                'UPDATE admins
                 SET admin_full_name = :admin_full_name, admin_email = :admin_email, admin_role = :admin_role, admin_status = :admin_status
                 WHERE admin_id = :admin_id',
                $new + ['admin_id' => $admin_id]
            );
            $this->checkASuperAdminRemains($db);

            (new AuditLog($db))->record('admin', $current_admin_id, 'admin.updated', 'admin', $admin_id,
                array_intersect_key($old, $new), $new);
        });
    }

    /** "Reset password": fields admin_password + admin_password_confirmation. Also unlocks the account. */
    public function resetPassword(int $admin_id, array $input, int $current_admin_id): void
    {
        $this->getAdminById($admin_id);
        $data = Validator::validate($input, $this->passwordRules());
        $this->checkPasswordConfirmation($data);

        $this->db->execute(
            'UPDATE admins SET admin_password_hash = :password_hash, admin_failed_logins = 0, admin_locked_until = NULL
             WHERE admin_id = :admin_id',
            ['password_hash' => password_hash($data['admin_password'], PASSWORD_DEFAULT), 'admin_id' => $admin_id]
        );
        (new AuditLog($this->db))->record('admin', $current_admin_id, 'admin.password_reset', 'admin', $admin_id);
    }

    private function detailRules(): array
    {
        return [
            'admin_full_name' => 'required|string|min:2|max:100',
            'admin_email'     => 'required|email|max:150',
            'admin_role'      => 'required|in:' . implode(',', array_keys(Admin::ROLE_NAMES)),
        ];
    }

    private function passwordRules(): array
    {
        return [
            'admin_password'              => 'required|string|min:' . self::MIN_PASSWORD_LENGTH . '|max:200',
            'admin_password_confirmation' => 'required|string|max:200',
        ];
    }

    private function checkPasswordConfirmation(array $data): void
    {
        if (!hash_equals($data['admin_password'], $data['admin_password_confirmation'])) {
            throw ApiException::validation(['admin_password_confirmation' => 'The passwords do not match.']);
        }
    }

    private function checkEmailIsFree(string $email, ?int $own_admin_id): void
    {
        $taken = $this->db->fetchValue(
            'SELECT 1 FROM admins WHERE admin_email = :admin_email AND admin_id <> :own_admin_id',
            ['admin_email' => strtolower($email), 'own_admin_id' => $own_admin_id ?? 0]
        );
        if ($taken) {
            throw ApiException::validation(['admin_email' => 'Another staff account already uses this email.']);
        }
    }

    /** Runs inside the update transaction, so a change that leaves no super admin is undone. */
    private function checkASuperAdminRemains(Database $db): void
    {
        $active_super_admins = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM admins WHERE admin_role = 'super_admin' AND admin_status = 'active'"
        );
        if ($active_super_admins === 0) {
            throw ApiException::validation(['admin_role' => 'There must always be at least one active super admin.']);
        }
    }
}
