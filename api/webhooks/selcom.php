<?php

/** Selcom callback endpoint. It rejects callbacks until official verification rules are implemented. */
require dirname(__DIR__, 2) . '/bootstrap.php';

ini_set('display_errors', '0');
$request_id = bin2hex(random_bytes(8));
Logger::setRequestId($request_id);

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw ApiException::methodNotAllowed();
    }
    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $content_type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_contains($content_type, 'application/json')) {
        throw ApiException::badRequest('INVALID_WEBHOOK', 'Taarifa za malipo lazima zitumwe kama JSON.');
    }
    $raw_body = file_get_contents('php://input');
    if (!is_string($raw_body) || strlen($raw_body) > 65536) {
        throw ApiException::badRequest('INVALID_WEBHOOK', 'Taarifa za malipo si sahihi au ni kubwa mno.');
    }
    (new PaymentWebhookHandler(new SelcomGateway()))->process($raw_body, $headers);
    $response = Response::success(['received' => true]);
} catch (ApiException $exception) {
    $response = Response::fromException($exception);
} catch (Throwable $exception) {
    Logger::exception($exception, ['endpoint' => 'selcom_webhook']);
    $response = Response::error('SERVER_ERROR', 'Taarifa ya malipo haikuweza kushughulikiwa.', 500);
}

$response->withHeader('X-Request-Id', $request_id)->send();
