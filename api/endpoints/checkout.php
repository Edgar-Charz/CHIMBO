<?php

/**
 * Checkout choices and the totals before placing the order ("Hakiki"). All endpoints need a login.
 *
 * @var Router $router
 */

$router->group('/checkout', function (Router $router) {

    // GET /api/v1/checkout/options?address_id=3 — delivery methods (for that address, if given) + payment methods
    $router->get('/options', function (Request $request) {
        $checkout = new Checkout(Database::instance());
        return Response::success($checkout->getOptions($request->user()['user_id'], $request->allQuery()));
    });

    // POST /api/v1/checkout/preview — totals. Body: { "delivery_method_id": 1, "address_id": 3 }
    $router->post('/preview', function (Request $request) {
        $checkout = new Checkout(Database::instance());
        return Response::success($checkout->preview($request->user()['user_id'], $request->all()));
    });

}, [AuthMiddleware::class]);
