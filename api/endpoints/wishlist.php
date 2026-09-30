<?php

/**
 * Saved products (the heart button). All endpoints need a login.
 *
 * @var Router $router
 */

$router->group('/wishlist', function (Router $router) {

    // GET /api/v1/wishlist — saved products as cards, most recently saved first
    $router->get('', function (Request $request) {
        $wishlist = new Wishlist(Database::instance());
        return Response::success($wishlist->getSavedProducts($request->user()['user_id']));
    });

    // GET /api/v1/wishlist/ids — only the ids, to fill in the hearts on product cards
    $router->get('/ids', function (Request $request) {
        $wishlist = new Wishlist(Database::instance());
        return Response::success($wishlist->getSavedProductIds($request->user()['user_id']));
    });

    // POST /api/v1/wishlist — save. Body: { "product_id": 7 }
    $router->post('', function (Request $request) {
        (new Wishlist(Database::instance()))->saveProduct($request->user()['user_id'], $request->all());
        return Response::success(null);
    });

    // DELETE /api/v1/wishlist/{product_id} — remove
    $router->delete('/{product_id}', function (Request $request) {
        (new Wishlist(Database::instance()))->removeProduct($request->user()['user_id'], (int) $request->param('product_id'));
        return Response::success(null);
    });

}, [AuthMiddleware::class]);
