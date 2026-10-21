<?php

/** Customer mobile push registrations. @var Router $router */
$router->group('/devices', function (Router $router) {
    // POST /api/v1/devices — register this app installation's FCM token.
    $router->post('', function (Request $request) {
        (new DeviceToken(Database::instance()))->register(
            $request->user()['user_id'],
            $request->bearerToken(),
            $request->all()
        );
        return Response::success(null);
    });

    // DELETE /api/v1/devices — remove the app installation's FCM token on logout.
    $router->delete('', function (Request $request) {
        (new DeviceToken(Database::instance()))->remove($request->user()['user_id'], $request->all());
        return Response::success(null);
    });
}, [AuthMiddleware::class]);
