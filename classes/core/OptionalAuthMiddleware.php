<?php

/**
 * For public endpoints that show more to a logged-in customer (e.g. Home adds "recently ordered").
 * If a valid login is sent, $request->user() is set; if not, the request continues as a guest — never a 401.
 */
class OptionalAuthMiddleware
{
    public function handle(Request $request): void
    {
        try {
            (new AuthMiddleware())->handle($request);
        } catch (ApiException $e) {
            if ($e->errorCode() !== 'UNAUTHENTICATED') {
                throw $e;
            }
            $request->setUser(null); // a guest
        }
    }
}
