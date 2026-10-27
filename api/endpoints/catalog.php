<?php

/**
 * Shop catalog: Home, categories and product lists. Public â€” guests can browse (website).
 * Rules live in the Home, Category and Product classes.
 *
 * @var Router $router
 */

// GET /api/v1/home â€” everything the Home tab shows, in one request.
// Public; with a login it also returns the customer's "recently ordered" products.
$router->get('/home', function (Request $request) {
    $user_id = $request->user()['user_id'] ?? null;
    $language = strtolower(substr($request->header('Accept-Language', 'sw') ?? 'sw', 0, 2));
    return Response::success((new Home(Database::instance()))->getHome($user_id, $language));
}, [OptionalAuthMiddleware::class]);

// GET /api/v1/categories â€” top categories with their chips
$router->get('/categories', function (Request $request) {
    return Response::success((new Category(Database::instance()))->getCategoryTree()); 
});

// GET /api/v1/categories/{id} â€” one top category with its chips
$router->get('/categories/{id}', function (Request $request) {
    $category_model = new Category(Database::instance());
    return Response::success($category_model->getCategoryById((int) $request->param('id')));
});

// GET /api/v1/products?category_id=1&q=vaseline&collection=deals&min_price=&max_price=&max_moq=&sort=popular&page=1&per_page=20
$router->get('/products', function (Request $request) {
    $result = (new Product(Database::instance()))->getProducts($request->allQuery());
    return Response::paginated($result['items'], $result['total'], $result['page'], $result['per_page']);
});

// POST /api/v1/products/search-by-image â€” visual search (camera/gallery image upload or base64)
$router->post('/products/search-by-image', function (Request $request) {
    $visual_search = new VisualSearch(Database::instance());
    return Response::success($visual_search->search($request));
});

// GET /api/v1/products/{id} â€” the product page: tiers, stock, delivery days and all photos in order
$router->get('/products/{id}', function (Request $request) {
    return Response::success((new Product(Database::instance()))->getProductById((int) $request->param('id')));
});

// GET /api/v1/products/{id}/related â€” "you may also like" (same chip)
$router->get('/products/{id}/related', function (Request $request) {
    return Response::success((new Product(Database::instance()))->getRelatedProducts((int) $request->param('id')));
});
