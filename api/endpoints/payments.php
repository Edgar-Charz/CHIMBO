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

    // POST /api/v1/payments/initiate â€” requires a reusable Idempotency-Key request header.
    $router->post('/initiate', function (Request $request) {
        $processor = new PaymentProcessor(Database::instance(), new SelcomGateway());
        $result = $processor->initiatePayment(
            (int) $request->user()['user_id'],
            $request->all(),
            (string) $request->header('Idempotency-Key', '')
        );
        return Response::created($result);
    });

    // GET /api/v1/payments/{id}/status â€” the processor limits results to the authenticated owner.
    $router->get('/{id}/status', function (Request $request) {
        $payment_id = filter_var($request->param('id'), FILTER_VALIDATE_INT);
        if ($payment_id === false || $payment_id < 1) {
            throw ApiException::notFound('Malipo hayakupatikana.');
        }
        $processor = new PaymentProcessor(Database::instance(), new SelcomGateway());
        return Response::success($processor->getPaymentStatus((int) $request->user()['user_id'], $payment_id));
    });
}, [AuthMiddleware::class]);
