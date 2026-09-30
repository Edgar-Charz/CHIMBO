<?php

/**
 * PHP sessions with safe settings (used by the admin dashboard and, later, the website).
 *
 * How to use it:
 *   Session::start('CHIMBO_ADMIN', '/chimbo/admin');   // once, at the top of every page
 *   Session::set('admin_id', 5);
 *   Session::get('admin_id');
 *   Session::flash('success', 'Product saved.');       // a message shown once on the next page
 *   Session::takeFlash('success');                     // read it (and remove it)
 */
class Session
{
    /**
     * Starts the session with a secure cookie:
     * - httponly: JavaScript cannot read the cookie (protects against stolen sessions)
     * - samesite: 'Strict' (admin) — other websites can never send this cookie;
     *             'Lax' (shop) — also sent when a customer opens a link to the shop, e.g. from WhatsApp
     * - secure: sent only over HTTPS (switched on automatically when the site uses HTTPS)
     * - path: the cookie is only sent to this part of the site (admin cookie never goes to the shop)
     */
    public static function start(string $cookie_name, string $cookie_path = '/', string $same_site = 'Strict'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');   // refuse session ids the server did not create
        ini_set('session.use_only_cookies', '1');  // never accept a session id from the URL

        session_name($cookie_name);
        session_set_cookie_params([
            'lifetime' => 0,                       // ends when the browser closes
            'path'     => $cookie_path,
            'secure'   => isHttpsRequest(),
            'httponly' => true,
            'samesite' => $same_site,
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Saves a message to show once, on the next page (e.g. after a redirect). */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }

    /** Returns the flash message of this type and removes it, or null. */
    public static function takeFlash(string $type): ?string
    {
        $message = $_SESSION['flash'][$type] ?? null;
        unset($_SESSION['flash'][$type]);
        return $message;
    }

    /** Gives the session a new id — always done right after login, so an old id cannot be reused. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Ends the session completely: clears the data, the cookie and the server file. */
    public static function destroy(): void
    {
        $_SESSION = [];

        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $cookie['path'],
            'secure'   => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'],
        ]);

        session_destroy();
    }
}
