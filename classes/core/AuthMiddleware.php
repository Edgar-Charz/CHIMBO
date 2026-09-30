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

        $user_id = $request->bearerToken() !== null
            ? (new AuthToken($db))->findUserIdByToken($request->bearerToken())
            : $this->userIdFromWebsiteSession($request);

        if ($user_id === null || !(new User($db))->isActiveUser($user_id)) {
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
