<?php

/**
 * Orders ("Oda"). All endpoints need a login. Rules live in the Order class.
 *
 * @var Router $router
 */

$router->group('/orders', function (Router $router) {

    // POST /api/v1/orders — "Thibitisha Oda": turns the cart into an order.
    // Body: { "address_id", "delivery_method_id", "payment_method", "expected_total", "order_customer_note" }
    // Header (recommended): "Idempotency-Key: <random id, one per checkout>" — a repeated request returns the same order.
    $router->post('', function (Request $request) {
        $channel = $request->bearerToken() !== null ? 'app' : 'web';
        $order   = (new Order(Database::instance()))->placeOrder(
            $request->user()['user_id'], $request->all(), $channel, $request->header('Idempotency-Key')
        );
        return Response::created($order);
    });

    // GET /api/v1/orders?group=active|delivered|all&page=1 — "Oda Zangu", newest first
    $router->get('', function (Request $request) {
        $result = (new Order(Database::instance()))->getOrders($request->user()['user_id'], $request->allQuery());
        return Response::paginated($result['items'], $result['total'], $result['page'], $result['per_page']);
    });

    // GET /api/v1/orders/{id} — detail with items, the tracking timeline ("events") and the delivery agent
    $router->get('/{id}', function (Request $request) {
        return Response::success((new Order(Database::instance()))->getOrder($request->user()['user_id'], (int) $request->param('id')));
    });

    // GET /api/v1/orders/{id}/tracking — "Fuatilia Oda" (the same data as the detail)
    $router->get('/{id}/tracking', function (Request $request) {
        return Response::success((new Order(Database::instance()))->getOrder($request->user()['user_id'], (int) $request->param('id')));
    });

    // POST /api/v1/orders/{id}/cancel — only before packing. Body (optional): { "order_cancel_reason": "…" }
    $router->post('/{id}/cancel', function (Request $request) {
        $order_model = new Order(Database::instance());
        return Response::success($order_model->cancelOrder($request->user()['user_id'], (int) $request->param('id'), $request->all()));
    });

    // POST /api/v1/orders/{id}/reorder — "Agiza Tena": puts the products back in the cart → the cart + skipped_items
    $router->post('/{id}/reorder', function (Request $request) {
        return Response::success((new Order(Database::instance()))->reorder($request->user()['user_id'], (int) $request->param('id')));
    });

}, [AuthMiddleware::class]);
