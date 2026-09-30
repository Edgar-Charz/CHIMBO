<?php

/**
 * CSRF protection — the same idea as SCMRS includes/csrf.php.
 * Every form gets a secret token; a POST without the right token is rejected,
 * so another website cannot submit forms on behalf of a logged-in admin.
 *
 * How to use it:
 *   <form method="post"> <?= Csrf::field() ?> ... </form>     // in the form
 *   Csrf::verifyOrFail($_POST['csrf_token'] ?? null);          // when handling the POST
 */
class Csrf
{
    /** The token for this session (created the first time it is needed). */
    public static function token(): string
    {
        $token = Session::get('csrf_token');
        if ($token === null) {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf_token', $token);
        }
        return $token;
    }

    /** The hidden input to put inside every POST form. */
    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(self::token()) . '">';
    }

    /** True when the submitted token matches the session token. */
    public static function isValid(?string $submitted_token): bool
    {
        $session_token = Session::get('csrf_token');

        // hash_equals compares in constant time, so the token cannot be guessed by timing
        return $session_token !== null && $submitted_token !== null && hash_equals($session_token, $submitted_token);
    }

    /** Stops the request with "403 Forbidden" when the token is missing or wrong. */
    public static function verifyOrFail(?string $submitted_token): void
    {
        if (!self::isValid($submitted_token)) {
            http_response_code(403);
            exit('Security check failed. Please go back, refresh the page and try again.');
        }
    }

    /** For API calls from the website (token sent in the X-CSRF-Token header): throws a 403 JSON error. */
    public static function verifyOrThrow(?string $submitted_token): void
    {
        if (!self::isValid($submitted_token)) {
            throw new ApiException(403, 'CSRF_INVALID', 'Ukurasa umepitwa na muda. Tafadhali onyesha upya ukurasa kisha ujaribu tena.');
        }
    }
}
