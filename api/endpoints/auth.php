<?php

/**
 * Customer login: phone → PIN, or phone → SMS code → create PIN (new customers and "Umesahau PIN?").
 * All rules live in CustomerAuth, CustomerPin and User; these blocks only connect the URL to the class.
 *
 * @var Router $router
 */

// The website must prove a login comes from our own page (CSRF token from the page)
$check_website_login = function (Request $request): void {
    if ($request->input('client') === 'web') {
        CustomerSession::start();
        Csrf::verifyOrThrow($request->header('X-CSRF-Token'));
    }
};

// POST /api/v1/auth/start — the phone number screen. Answers next_step: "pin" | "pin_locked" | "otp"
// (for "otp" the SMS code has already been sent). Body: { "user_phone": "0712345678" }
$router->post('/auth/start', function (Request $request) {
    $customer_auth = new CustomerAuth(Database::instance());
    return Response::success($customer_auth->startLogin($request->all(), $request->ip()));
});

// POST /api/v1/auth/pin/login — the PIN screen. Same answer as /auth/otp/verify.
// Body: { "user_phone": "...", "user_pin": "4826", "client": "app" | "web",
//         "auth_token_device_name": "Tecno Spark 10", "auth_token_platform": "android" }
$router->post('/auth/pin/login', function (Request $request) use ($check_website_login) {
    $check_website_login($request);
    $customer_auth = new CustomerAuth(Database::instance());
    return Response::success($customer_auth->logInWithPin($request->all(), $request->ip())); 
});

// POST /api/v1/auth/otp/request — send the SMS code ("Umesahau PIN?" and "Tuma tena")
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

// POST /api/v1/auth/otp/verify — check the SMS code and log in
// Body: { "user_phone": "...", "otp_code": "123456", "client": "app" | "web",
//         "auth_token_device_name": "Tecno Spark 10", "auth_token_platform": "android" }
$router->post('/auth/otp/verify', function (Request $request) use ($check_website_login) {
    $check_website_login($request);
    $customer_auth = new CustomerAuth(Database::instance());
    return Response::success($customer_auth->verifyLoginCode($request->all(), $request->ip()));
});

// Endpoints below need a logged-in customer
$router->group('/auth', function (Router $router) {

    // POST /api/v1/auth/pin — "Tengeneza PIN", new PIN after "Umesahau PIN?", and "Badilisha PIN"
    // Body: { "user_pin": "4826", "user_pin_confirmation": "4826", "current_pin": "1357" (only when changing) }
    $router->post('/pin', function (Request $request) {
        $customer_auth = new CustomerAuth(Database::instance());
        return Response::success($customer_auth->savePin($request->user()['user_id'], $request->bearerToken(), $request->all()));
    });

    // POST /api/v1/auth/profile — the business details step for new customers ("Tuambie Kuhusu Biashara Yako")
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
