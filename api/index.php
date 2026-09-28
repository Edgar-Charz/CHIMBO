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

$requestId = bin2hex(random_bytes(8));
Logger::setRequestId($requestId);

// Last safety net: fatal errors (e.g. a syntax error) still return JSON
register_shutdown_function(function () use ($requestId): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    Logger::error('Fatal error: ' . $error['message'], ['file' => $error['file'] . ':' . $error['line']]);
    if (!headers_sent()) {
        Response::error('SERVER_ERROR', 'Kuna tatizo upande wetu. Tafadhali jaribu tena baadaye.', 500)
            ->withHeader('X-Request-Id', $requestId)
            ->send();
    }
});

try {
    $request = Request::fromGlobals(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'));

    $router = new Router();
    require BASE_PATH . '/routes/api.php';

    $response = $router->dispatch($request);
} catch (ApiException $e) {
    // Expected errors: validation, not found, unauthenticated …
    $response = Response::fromException($e);
} catch (Throwable $e) {
    // Unexpected errors: log everything, tell the client only what is safe
    Logger::exception($e);
    $debug = Env::get('APP_DEBUG', false)
        ? ['exception' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]
        : null;
    $response = Response::error('SERVER_ERROR', 'Kuna tatizo upande wetu. Tafadhali jaribu tena baadaye.', 500, [], $debug);
}

$response->withHeader('X-Request-Id', $requestId)->send();
