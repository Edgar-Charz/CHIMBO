<?php

/**
 * CHIMBO API front controller.
 * Every request to /api/... arrives here (see api/.htaccess), is routed to a controller,
 * and always leaves as JSON in the standard format — including errors.
 */

require dirname(__DIR__) . '/bootstrap.php';

// The API never prints raw PHP errors (they would break the JSON); details go to the log,
// and to "error.debug" in the response when APP_DEBUG=true.
ini_set('display_errors', '0');

$request_id = bin2hex(random_bytes(8));
Logger::setRequestId($request_id);

// Last safety net: fatal errors (e.g. a syntax error) still return JSON
register_shutdown_function(function () use ($request_id): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    Logger::error('Fatal error: ' . $error['message'], ['file' => $error['file'] . ':' . $error['line']]);
    if (!headers_sent()) {
        Response::error('SERVER_ERROR', 'Kuna tatizo upande wetu. Tafadhali jaribu tena baadaye.', 500)
            ->withHeader('X-Request-Id', $request_id)
            ->send();
    }
});

try {
    $request = Request::fromGlobals(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'));

    // Load every API file (api/endpoints/*.php); each one adds its routes under /v1
    $router = new Router();
    $router->group('/v1', function (Router $router) {
        foreach (glob(__DIR__ . '/endpoints/*.php') as $endpoint_file) {
            require $endpoint_file;
        }
    });

    $response = $router->dispatch($request);
} catch (ApiException $exception) {
    // Expected errors: validation, not found, unauthenticated …
    $response = Response::fromException($exception);
} catch (Throwable $exception) {
    // Unexpected errors: log everything, tell the client only what is safe
    Logger::exception($exception);
    $debug = Env::get('APP_DEBUG', false)
        ? [
            'exception' => get_class($exception),
            'message'   => $exception->getMessage(),
            'file'      => $exception->getFile() . ':' . $exception->getLine(),
        ]
        : null;
    $response = Response::error('SERVER_ERROR', 'Kuna tatizo upande wetu. Tafadhali jaribu tena baadaye.', 500, [], $debug);
}

$response->withHeader('X-Request-Id', $request_id)->send();
