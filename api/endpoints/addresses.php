<?php

/**
 * Delivery addresses ("Anwani za Usafirishaji"). All endpoints need a login.
 *
 * @var Router $router
 */

$router->group('/addresses', function (Router $router) {

    // GET /api/v1/addresses — the default one first
    $router->get('', function (Request $request) {
        return Response::success((new Address(Database::instance()))->getAddresses($request->user()['user_id']));
    });

    // POST /api/v1/addresses — the first address becomes the default
    // Body: { "address_recipient_name", "address_phone", "region_id", "district_id", "address_street",
    //         "address_landmark", "address_is_default" }
    $router->post('', function (Request $request) {
        $address_model = new Address(Database::instance());
        return Response::created($address_model->createAddress($request->user()['user_id'], $request->all()));
    });

    // PATCH /api/v1/addresses/{id} — same body as POST
    $router->patch('/{id}', function (Request $request) {
        $address_model = new Address(Database::instance());
        return Response::success($address_model->updateAddress($request->user()['user_id'], (int) $request->param('id'), $request->all()));
    });

    // POST /api/v1/addresses/{id}/default — make it the default; returns all addresses
    $router->post('/{id}/default', function (Request $request) {
        $address_model = new Address(Database::instance());
        return Response::success($address_model->setDefaultAddress($request->user()['user_id'], (int) $request->param('id')));
    });

    // DELETE /api/v1/addresses/{id} — returns the remaining addresses
    $router->delete('/{id}', function (Request $request) {
        $address_model = new Address(Database::instance());
        return Response::success($address_model->deleteAddress($request->user()['user_id'], (int) $request->param('id')));
    });

}, [AuthMiddleware::class]);
