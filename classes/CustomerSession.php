<?php

/**
 * Website login for customers (the mobile app uses tokens instead — see AuthToken).
 * The cookie is valid for the whole shop (including /api, which the website's JavaScript calls).
 */
class CustomerSession
{
    public static function start(): void
    {
        $shop_path = (string) parse_url(url(''), PHP_URL_PATH);  // e.g. "/chimbo/"
        Session::start('CHIMBO_SID', $shop_path, 'Lax');
    }

    public static function logIn(int $user_id): void
    {
        self::start();
        Session::regenerate(); // new session id at login, so an old id cannot be reused
        Session::set('customer_user_id', $user_id);
    }

    public static function logOut(): void
    {
        self::start();
        Session::destroy();
    }

    /** The logged-in customer's id, or null. */
    public static function currentUserId(): ?int
    {
        self::start();
        $user_id = Session::get('customer_user_id');
        return $user_id === null ? null : (int) $user_id;
    }
}
