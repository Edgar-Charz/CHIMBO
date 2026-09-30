<?php

/**
 * The cart ("Kikapu"). All endpoints need a login and return the whole priced cart.
 * Rules (tier prices, MOQ, stock) live in the Cart class and Pricing.
 *
 * @var Router $router
 */

$router->group('/cart', function (Router $router) {

    // GET /api/v1/cart
    $router->get('', function (Request $request) {
        return Response::success((new Cart(Database::instance()))->getCart($request->user()['user_id']));
    });

    // POST /api/v1/cart/items — add pieces. Body: { "product_id": 1, "quantity": 8 }
    $router->post('/items', function (Request $request) {
        return Response::success((new Cart(Database::instance()))->addItem($request->user()['user_id'], $request->all()));
    });

    // PATCH /api/v1/cart/items/{product_id} — set the quantity. Body: { "quantity": 12 }
    $router->patch('/items/{product_id}', function (Request $request) {
        $cart = new Cart(Database::instance());
        return Response::success($cart->setQuantity($request->user()['user_id'], (int) $request->param('product_id'), $request->all()));
    });

    // DELETE /api/v1/cart/items/{product_id}
    $router->delete('/items/{product_id}', function (Request $request) {
        $cart = new Cart(Database::instance());
        return Response::success($cart->removeItem($request->user()['user_id'], (int) $request->param('product_id')));
    });

    // DELETE /api/v1/cart — empty the cart
    $router->delete('', function (Request $request) {
        return Response::success((new Cart(Database::instance()))->clearCart($request->user()['user_id']));
    });

    // POST /api/v1/cart/merge — website: move the guest cart into the account after login
    // Body: { "items": [ { "product_id": 1, "quantity": 8 }, … ] }
    $router->post('/merge', function (Request $request) {
        return Response::success((new Cart(Database::instance()))->mergeGuestCart($request->user()['user_id'], $request->all()));
    });

}, [AuthMiddleware::class]);
