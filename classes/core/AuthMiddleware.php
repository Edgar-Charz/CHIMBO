<?php

/**
 * Protects API endpoints that need a logged-in customer.
 *
 * - Mobile app: sends "Authorization: Bearer <token>".
 * - Website: sends its session cookie; every change (POST, PATCH, DELETE) must also send the
 *   CSRF token in the "X-CSRF-Token" header.
 *
 * On success the user id is available in the endpoint as $request->user()['user_id'].
 * Otherwise the request stops with 401 (not logged in) or 403 (bad CSRF token).
 */
class AuthMiddleware
{
    public function handle(Request $request): void
    {
        $db = Database::instance();

        $is_app  = $request->bearerToken() !== null;
        $user_id = $is_app
            ? (new AuthToken($db))->findUserIdByToken($request->bearerToken())
            : $this->userIdFromWebsiteSession($request);

        // Website logins older than a "log out everywhere" (e.g. PIN changed) no longer count
        $logged_in_at = $is_app ? null : (CustomerSession::loggedInAt() ?? '1970-01-01 00:00:00');

        if ($user_id === null || !(new User($db))->isActiveUser($user_id, $logged_in_at)) {
            throw ApiException::unauthenticated('Tafadhali ingia tena ili kuendelea.');
        }

        $request->setUser(['user_id' => $user_id]);
    }

    private function userIdFromWebsiteSession(Request $request): ?int
    {
        $user_id = CustomerSession::currentUserId();

        $is_change = !in_array($request->method(), ['GET', 'HEAD'], true);
        if ($user_id !== null && $is_change) {
            Csrf::verifyOrThrow($request->header('X-CSRF-Token'));
        }

        return $user_id;
    }
}
