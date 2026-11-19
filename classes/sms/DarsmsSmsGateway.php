<?php

/** Sends SMS through DARSMS. Successful HTTP responses mean accepted for processing, not delivered. */
class DarsmsSmsGateway implements SmsGateway
{
    private Closure $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = Closure::fromCallable($transport ?? $this->sendRequest(...));
    }

    public function send(string $phone, string $message): array
    {
        $api_key = Env::required('DARSMS_API_KEY');
        $sender_id = Env::required('DARSMS_SENDER_ID');
        $api_url = Env::get('DARSMS_API_URL', 'https://dev.darsms.co.tz/api/v1/integrations/sms/send');

        if (!filter_var($api_url, FILTER_VALIDATE_URL) || !str_starts_with($api_url, 'https://')) {
            throw new RuntimeException('DARSMS_API_URL must be a valid HTTPS URL.');
        }

        $payload = json_encode([
            'senderId' => $sender_id,
            'to' => [$phone],
            'message' => $message,
        ], JSON_THROW_ON_ERROR);

        $response_body = $this->request('POST', $api_url, $api_key, $payload);
        if (($response_body['success'] ?? false) !== true) {
            throw new RuntimeException('DARSMS did not accept the SMS request.');
        }

        $message_id = $response_body['data']['messageIds'][0] ?? null;
        if (!is_string($message_id) || $message_id === '') {
            throw new RuntimeException('DARSMS accepted no trackable SMS message.');
        }

        return ['status' => 'queued', 'message_id' => $message_id];
    }

    /** Returns the normalized provider status for a previously accepted message. */
    public function getMessageStatus(string $message_id): string
    {
        $api_key = Env::required('DARSMS_API_KEY');
        $send_url = Env::get('DARSMS_API_URL', 'https://dev.darsms.co.tz/api/v1/integrations/sms/send');
        $status_url = preg_replace('~/send/?$~', '/status/' . rawurlencode($message_id), $send_url);

        if (!is_string($status_url) || $status_url === $send_url) {
            throw new RuntimeException('DARSMS_API_URL must end with /send to check message delivery.');
        }
        if (!filter_var($status_url, FILTER_VALIDATE_URL) || !str_starts_with($status_url, 'https://')) {
            throw new RuntimeException('DARSMS status URL must be a valid HTTPS URL.');
        }

        $response_body = $this->request('GET', $status_url, $api_key);
        if (($response_body['success'] ?? false) !== true) {
            throw new RuntimeException('DARSMS could not return the SMS delivery status.');
        }

        $status = strtoupper((string) ($response_body['data']['status'] ?? ''));
        return match ($status) {
            'QUEUED' => 'queued',
            'SENT' => 'sent',
            'DELIVERED' => 'delivered',
            'FAILED', 'REJECTED' => 'failed',
            default => throw new RuntimeException('DARSMS returned an unknown SMS delivery status.'),
        };
    }

    /** Sends or checks a request without logging credentials, phone numbers or message contents. */
    private function request(string $method, string $api_url, string $api_key, ?string $payload = null): array
    {
        $response = ($this->transport)($method, $api_url, $api_key, $payload);
        $status_code = (int) ($response['status_code'] ?? 0);
        if ($status_code < 200 || $status_code >= 300) {
            throw new RuntimeException("DARSMS returned HTTP {$status_code}.");
        }

        $response_body = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($response_body)) {
            throw new RuntimeException('DARSMS returned an invalid response.');
        }

        return $response_body;
    }

    /** Sends or checks a request without logging credentials, phone numbers or message contents. */
    private function sendRequest(string $method, string $api_url, string $api_key, ?string $payload): array
    {
        $curl = curl_init($api_url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize DARSMS request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $api_key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);

        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($curl);
        $status_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error_number = curl_errno($curl);
        curl_close($curl);

        if ($body === false || $error_number !== 0) {
            throw new RuntimeException('DARSMS network request failed.');
        }

        return ['status_code' => $status_code, 'body' => $body];
    }
}
