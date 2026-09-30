<?php

/**
 * Customer login: phone + OTP (registration and login are the same steps).
 * All rules live in CustomerAuth and User; these blocks only connect the URL to the class.
 *
 * @var Router $router
 */

// POST /api/v1/auth/otp/request — step 1 (also used for "Tuma tena")
// Body: { "user_phone": "0712345678" }
$router->post('/auth/otp/request', function (Request $request) {
    $customer_auth = new CustomerAuth(Database::instance());
    return Response::success($customer_auth->requestLoginCode($request->all(), $request->ip()));
});

// GET /api/v1/auth/csrf — website only: starts the shop session and returns its CSRF token.
// The website's JavaScript sends it back in the "X-CSRF-Token" header on every change.
// (Other websites cannot read this response, because the API allows no cross-site access.)
$router->get('/auth/csrf', function (Request $request) {
    CustomerSession::start();
    return Response::success(['csrf_token' => Csrf::token()]);
});
 
// POST /api/v1/auth/otp/verify — step 2
// Body: { "user_phone": "...", "otp_code": "123456", "client": "app" | "web",
//         "auth_token_device_name": "Tecno Spark 10", "auth_token_platform": "android" }
$router->post('/auth/otp/verify', function (Request $request) {
    // The website must prove the request comes from our own page (CSRF token from the page)
    if ($request->input('client') === 'web') {
        CustomerSession::start();
        Csrf::verifyOrThrow($request->header('X-CSRF-Token'));
    }

    $customer_auth = new CustomerAuth(Database::instance());
    return Response::success($customer_auth->verifyLoginCode($request->all(), $request->ip()));
});

// Endpoints below need a logged-in customer
$router->group('/auth', function (Router $router) {

    // POST /api/v1/auth/profile — step 3 for new customers ("Tuambie Kuhusu Biashara Yako")
    // Body: { "user_full_name": "...", "business_name": "...", "region_id": 2, "district_id": 7 }
    $router->post('/profile', function (Request $request) {
        $user_model = new User(Database::instance());
        return Response::success($user_model->completeProfile($request->user()['user_id'], $request->all()));
    });

    // GET /api/v1/auth/me — the logged-in customer (used when the app starts)
    $router->get('/me', function (Request $request) {
        $user_model = new User(Database::instance());
        return Response::success($user_model->getProfile($request->user()['user_id']));
    });

    // POST /api/v1/auth/logout
    $router->post('/logout', function (Request $request) {
        (new CustomerAuth(Database::instance()))->logOut($request->bearerToken());
        return Response::success(null);
    });

}, [AuthMiddleware::class]);
