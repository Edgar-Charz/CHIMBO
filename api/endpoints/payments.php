<?php

/**
 * The customer's payments ("Malipo yangu"). The payment methods themselves are public: GET /payment-methods (system.php).
 *
 * @var Router $router
 */

$router->group('/payments', function (Router $router) {

    // GET /api/v1/payments?page=1 — the customer's payments, newest first (20 per page)
    $router->get('', function (Request $request) {
        $result = (new Payment(Database::instance()))->getPaymentsForCustomer($request->user()['user_id'], $request->allQuery());
        return Response::paginated($result['items'], $result['total'], $result['page'], $result['per_page']);
    });

}, [AuthMiddleware::class]);
