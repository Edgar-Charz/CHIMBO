<?php

/**
 * The logged-in customer's own account ("Wasifu"). All endpoints need a login.
 * Rules live in the User class; these blocks only connect the URL to it.
 *
 * @var Router $router
 */
 
$router->group('/me', function (Router $router) {

    // GET /api/v1/me — the profile
    $router->get('', function (Request $request) {
        $user_model = new User(Database::instance());
        return Response::success($user_model->getProfile($request->user()['user_id']));
    });

    // PATCH /api/v1/me — name, email, language (send only what changes)
    // Body: { "user_full_name": "...", "user_email": "...", "user_locale": "sw" | "en" }
    $router->patch('', function (Request $request) {
        $user_model = new User(Database::instance());
        return Response::success($user_model->updateAccount($request->user()['user_id'], $request->all()));
    });

    // PATCH /api/v1/me/business — "Taarifa za Biashara"
    // Body: { "business_name": "...", "region_id": 2, "district_id": 8 }
    $router->patch('/business', function (Request $request) {
        $user_model = new User(Database::instance());
        return Response::success($user_model->updateBusiness($request->user()['user_id'], $request->all()));
    });

    // POST /api/v1/me/avatar — profile photo. Send as a file upload (multipart/form-data) with the field "avatar"
    // (JPG, PNG or WEBP, up to 8 MB). Returns the profile with the new user_avatar_url.
    $router->post('/avatar', function (Request $request) {
        $user_model = new User(Database::instance());
        return Response::success($user_model->setAvatar($request->user()['user_id'], $request->file('avatar')));
    });

    // DELETE /api/v1/me/avatar — remove the photo (the app shows initials again)
    $router->delete('/avatar', function (Request $request) {
        return Response::success((new User(Database::instance()))->removeAvatar($request->user()['user_id']));
    });

    // DELETE /api/v1/me — delete the account. Body: { "confirm": true }
    $router->delete('', function (Request $request) {
        (new User(Database::instance()))->deleteAccount($request->user()['user_id'], $request->all());

        // App tokens were revoked by deleteAccount(); the website session ends here
        if ($request->bearerToken() === null) {
            CustomerSession::logOut();
        }
        return Response::success(null);
    });

}, [AuthMiddleware::class]);
