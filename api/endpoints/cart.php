<?php

/**
 * The cart ("Kikapu"). Every endpoint returns the whole priced cart.
 * All need a login except POST /cart/preview (website guests; nothing is saved).
 * Rules (tier prices, MOQ, stock) live in the Cart class and Pricing.
 *
 * @var Router $router
 */

// POST /api/v1/cart/preview — PUBLIC: prices a website guest's cart (kept in the browser) without saving it.
// Body: { "items": [ { "product_id": 1, "quantity": 8 }, … ] } → the same shape as GET /cart.
// Read-only, so no login and no CSRF check are needed (sending X-CSRF-Token is harmless).
$router->post('/cart/preview', function (Request $request) {
    (new RateLimiter(Database::instance()))->hit('cart-preview:ip:' . $request->ip(), 600, 3600);
    return Response::success((new Cart(Database::instance()))->previewGuestCart($request->all()));
});

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
