<?php

/**
 * Website login for customers (the mobile app uses tokens instead — see AuthToken).
 * The cookie is valid for the whole shop (including /api, which the website's JavaScript calls).
 */
class CustomerSession
{
    private const PIN_RESET_SECONDS = 900;   // after an SMS code, this browser may set a new PIN for 15 minutes

    public static function start(): void
    {
        $shop_path = (string) parse_url(url(''), PHP_URL_PATH);  // e.g. "/chimbo/"
        Session::start('CHIMBO_SID', $shop_path, 'Lax');
    }

    /** $proved_by_sms = the login used an SMS code, so this browser may set a new PIN without the old one for a while. */
    public static function logIn(int $user_id, bool $proved_by_sms = false): void
    {
        self::start();
        Session::regenerate(); // new session id at login, so an old id cannot be reused
        Session::set('customer_user_id', $user_id);
        Session::set('customer_logged_in_at', gmdate('Y-m-d H:i:s'));  // compared with users.user_sessions_revoked_at
        Session::set('customer_pin_reset_until', $proved_by_sms ? time() + self::PIN_RESET_SECONDS : 0);
    }

    public static function logOut(): void
    {
        self::start();
        Session::destroy();
    }

    /** When this browser logged in (UTC), or null for sessions from before this was recorded. */
    public static function loggedInAt(): ?string
    {
        self::start();
        return Session::get('customer_logged_in_at');
    }

    public static function mayResetPin(): bool
    {
        self::start();
        return (int) Session::get('customer_pin_reset_until', 0) > time();
    }

    public static function endPinReset(): void
    {
        self::start();
        Session::set('customer_pin_reset_until', 0);
    }

    /** The logged-in customer's id, or null. */
    public static function currentUserId(): ?int
    {
        self::start();
        $user_id = Session::get('customer_user_id');
        return $user_id === null ? null : (int) $user_id;
    }
}
