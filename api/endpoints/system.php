<?php

/**
 * System endpoints.
 * Every file in api/endpoints/ is loaded by api/index.php; routes here live under /api/v1.
 *
 * @var Router $router
 */

// GET /api/v1/health — confirms the API is running and can reach the database
$router->get('/health', function (Request $request) {
    try {
        Database::instance()->fetchValue('SELECT 1');
        $database_status = 'ok';
    } catch (Throwable $exception) {
        Logger::exception($exception, ['check' => 'health.database']);
        $database_status = 'down';
    }

    $is_healthy = $database_status === 'ok';

    return Response::success([
        'status'   => $is_healthy ? 'ok' : 'degraded',
        'app'      => Env::get('APP_NAME', 'CHIMBO'),
        'version'  => 'v1',
        'database' => $database_status,
        'time'     => gmdate('c'),
    ], $is_healthy ? 200 : 503);
});
 