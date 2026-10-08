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
 
// GET /api/v1/support — "Msaada": support phone, WhatsApp (with a ready wa.me link) and hours
$router->get('/support', function (Request $request) {
    return Response::success((new Settings(Database::instance()))->getSupportContacts());
});

// GET /api/v1/pages/{page_name} — legal texts: "terms" (Vigezo na Masharti) or "privacy" (Sera ya Faragha)
$router->get('/pages/{page_name}', function (Request $request) {
    return Response::success((new Settings(Database::instance()))->getLegalPage((string) $request->param('page_name')));
});

// GET /api/v1/payment-methods — the switched-on payment methods with their "pay to" details (Lipa Namba, account name …)
$router->get('/payment-methods', function (Request $request) {
    return Response::success((new PaymentMethod(Database::instance()))->getActiveMethods());
});

// GET /api/v1/app-images — pictures staff uploaded for the app's fixed screens (onboarding, login, order success):
// [{app_image_slot, app_image_url, updated_at}]. Slots without an upload are left out → the app uses its built-in picture.
$router->get('/app-images', function (Request $request) {
    return Response::success((new AppImage(Database::instance()))->getImagesForApps());
});
