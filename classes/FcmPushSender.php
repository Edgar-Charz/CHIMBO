<?php

/** Sends customer notification pushes through Firebase Cloud Messaging HTTP v1. */
class FcmPushSender
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const MESSAGING_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private ?array $service_account = null;
    private ?string $access_token = null;

    public function isConfigured(): bool
    {
        $path = (string) Env::get('FCM_SERVICE_ACCOUNT_FILE', '');
        return $path !== '' && is_file($path) && is_readable($path);
    }

    /** Sends a push. Returns false only when FCM identifies a stale token. */
    public function send(string $device_token, string $title, string $body, array $data): bool
    {
        $account = $this->serviceAccount();
        $message = [
            'token' => $device_token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'chimbo_orders']],
            'apns' => ['headers' => ['apns-priority' => '10']],
        ];
        if (isset($data['url']) && str_starts_with($data['url'], 'https://')) {
            $message['webpush'] = [
                'headers' => ['Urgency' => 'high'],
                'fcm_options' => ['link' => $data['url']],
            ];
        }
        $response = $this->request(
            sprintf(self::MESSAGING_URL, rawurlencode($account['project_id'])),
            ['message' => $message],
            ['Authorization: Bearer ' . $this->accessToken()]
        );

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return true;
        }

        $error = $response['body']['error']['details'][0]['errorCode'] ?? null;
        if ($error === 'UNREGISTERED') {
            return false;
        }
        throw new RuntimeException('FCM send failed with HTTP ' . $response['status'] . '.');
    }

    private function serviceAccount(): array
    {
        if ($this->service_account !== null) {
            return $this->service_account;
        }

        $path = (string) Env::get('FCM_SERVICE_ACCOUNT_FILE', '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('FCM service account file is not configured or readable.');
        }
        $account = json_decode((string) file_get_contents($path), true);
        if (!is_array($account) || empty($account['project_id']) || empty($account['client_email']) || empty($account['private_key'])) {
            throw new RuntimeException('FCM service account file is invalid.');
        }

        return $this->service_account = $account;
    }

    private function accessToken(): string
    {
        if ($this->access_token !== null) {
            return $this->access_token;
        }

        $account = $this->serviceAccount();
        $issued_at = time();
        $claims = [
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $issued_at,
            'exp' => $issued_at + 3600,
        ];
        $unsigned_jwt = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
            . $this->base64UrlEncode(json_encode($claims));
        $private_key = openssl_pkey_get_private($account['private_key']);
        if ($private_key === false || !openssl_sign($unsigned_jwt, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the FCM service account assertion.');
        }

        $response = $this->request(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned_jwt . '.' . $this->base64UrlEncode($signature),
        ], [], true);
        if ($response['status'] !== 200 || empty($response['body']['access_token'])) {
            throw new RuntimeException('Google OAuth token request failed with HTTP ' . $response['status'] . '.');
        }

        return $this->access_token = $response['body']['access_token'];
    }

    private function request(string $url, array $body, array $headers = [], bool $form_encoded = false): array
    {
        $handle = curl_init($url);
        $headers[] = 'Accept: application/json';
        $headers[] = 'Content-Type: ' . ($form_encoded ? 'application/x-www-form-urlencoded' : 'application/json');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $form_encoded ? http_build_query($body) : json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw_body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($raw_body === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('FCM network request failed: ' . $message);
        }
        curl_close($handle);

        $decoded_body = json_decode($raw_body, true);
        return ['status' => $status, 'body' => is_array($decoded_body) ? $decoded_body : []];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
