<?php

/**
 * Shop catalog: Home, categories and product lists. Public — guests can browse (website).
 * Rules live in the Home, Category and Product classes.
 *
 * @var Router $router
 */

// GET /api/v1/home — everything the Home tab shows, in one request.
// Public; with a login it also returns the customer's "recently ordered" products.
$router->get('/home', function (Request $request) {
    $user_id = $request->user()['user_id'] ?? null;
    return Response::success((new Home(Database::instance()))->getHome($user_id));
}, [OptionalAuthMiddleware::class]);

// GET /api/v1/categories — top categories with their chips
$router->get('/categories', function (Request $request) {
    return Response::success((new Category(Database::instance()))->getCategoryTree()); 
});

// GET /api/v1/categories/{id} — one top category with its chips
$router->get('/categories/{id}', function (Request $request) {
    $category_model = new Category(Database::instance());
    return Response::success($category_model->getCategoryById((int) $request->param('id')));
});

// GET /api/v1/products?category_id=1&q=vaseline&collection=deals&min_price=&max_price=&max_moq=&sort=popular&page=1&per_page=20
$router->get('/products', function (Request $request) {
    $result = (new Product(Database::instance()))->getProducts($request->allQuery());
    return Response::paginated($result['items'], $result['total'], $result['page'], $result['per_page']);
});

// GET /api/v1/products/{id} — the product page: tiers, stock, delivery days and all photos in order
$router->get('/products/{id}', function (Request $request) {
    return Response::success((new Product(Database::instance()))->getProductById((int) $request->param('id')));
});

// GET /api/v1/products/{id}/related — "you may also like" (same chip)
$router->get('/products/{id}/related', function (Request $request) {
    return Response::success((new Product(Database::instance()))->getRelatedProducts((int) $request->param('id')));
});
