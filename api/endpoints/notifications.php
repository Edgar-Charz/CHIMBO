<?php

/**
 * In-app notifications (the bell). All endpoints need a login.
 *
 * @var Router $router
 */

$router->group('/notifications', function (Router $router) {

    // GET /api/v1/notifications?page=1 — newest first
    $router->get('', function (Request $request) {
        $result = (new Notification(Database::instance()))->getNotifications($request->user()['user_id'], $request->allQuery());
        return Response::paginated($result['items'], $result['total'], $result['page'], $result['per_page']);
    });

    // GET /api/v1/notifications/unread-count — the number on the bell
    $router->get('/unread-count', function (Request $request) {
        return Response::success(['unread_count' => (new Notification(Database::instance()))->getUnreadCount($request->user()['user_id'])]);
    });

    // POST /api/v1/notifications/read-all
    $router->post('/read-all', function (Request $request) {
        (new Notification(Database::instance()))->markAllAsRead($request->user()['user_id']);
        return Response::success(null);
    });

    // POST /api/v1/notifications/{id}/read
    $router->post('/{id}/read', function (Request $request) {
        (new Notification(Database::instance()))->markAsRead($request->user()['user_id'], (int) $request->param('id'));
        return Response::success(null);
    });

}, [AuthMiddleware::class]);
