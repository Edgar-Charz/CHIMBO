<?php

/**
 * Who is logged in to the admin dashboard.
 *
 * How to use it (at the top of every admin page):
 *   $current_admin = AdminSession::requireLogin('products.manage');
 *   // → not logged in: sent to the login page
 *   // → logged in without this permission: "403 — not allowed" page
 *   // → otherwise: returns the admin row
 */
class AdminSession
{
    private const IDLE_TIMEOUT_SECONDS = 1800; // logged out after 30 minutes without activity

    /** Starts the admin session. Its cookie is only sent to /admin pages. */
    public static function start(): void
    {
        $admin_path = (string) parse_url(url('admin/'), PHP_URL_PATH);
        Session::start('CHIMBO_ADMIN', $admin_path);
    }

    /** Saves the admin in the session after a successful login. */
    public static function logIn(array $admin): void
    {
        Session::regenerate(); // new session id, so an old id cannot be reused
        Session::set('admin_id', (int) $admin['admin_id']);
        Session::set('admin_last_activity', time());
    }

    public static function logOut(): void
    {
        Session::destroy();
    }

    /**
     * The logged-in admin, or null (not logged in, idle too long, or account disabled).
     * $is_activity = false for checks the page makes by itself (e.g. the notification badge every minute):
     * they don't reset the idle timer, so an admin who walks away is still logged out after 30 minutes.
     */
    public static function currentAdmin(bool $is_activity = true): ?array
    {
        $admin_id      = Session::get('admin_id');
        $last_activity = (int) Session::get('admin_last_activity', 0);

        if ($admin_id === null) {
            return null;
        }

        if (time() - $last_activity > self::IDLE_TIMEOUT_SECONDS) {
            self::logOut();
            return null;
        }

        // Read from the database on every page, so a disabled admin is locked out immediately
        $admin = (new Admin(Database::instance()))->getActiveAdminById((int) $admin_id);
        if ($admin === null) {
            self::logOut();
            return null;
        }

        if ($is_activity) {
            Session::set('admin_last_activity', time());
        }
        return $admin;
    }

    /** Returns the logged-in admin, or stops the page (login redirect or 403). */
    public static function requireLogin(?string $permission = null): array
    {
        $had_login = Session::get('admin_id') !== null;
        $admin     = self::currentAdmin();

        if ($admin === null) {
            // Logged in a moment ago but idle too long (or switched off): say why on the login page
            redirect(url($had_login ? 'admin/login.php?expired=1' : 'admin/login.php'));
        }

        if ($permission !== null && !Admin::can($admin, $permission)) {
            http_response_code(403);
            require BASE_PATH . '/admin/includes/forbidden.php';
            exit;
        }

        return $admin;
    }

    /**
     * The same check for admin/ajax/ files, which answer with JSON instead of a page:
     * logged out → 401, not allowed → 403 (the page's JavaScript then reloads or shows the message).
     * $is_activity: see currentAdmin().
     */
    public static function requireAjaxLogin(string $permission, bool $is_activity = true): array
    {
        $admin = self::currentAdmin($is_activity);

        $refusal = match (true) {
            $admin === null                  => [401, 'Your session has ended. Please log in again.'],
            !Admin::can($admin, $permission) => [403, 'Your role does not have access to this.'],
            default                          => null,
        };
        if ($refusal !== null) {
            http_response_code($refusal[0]);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $refusal[1]]);
            exit;
        }

        return $admin;
    }
}
