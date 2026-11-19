<?php

/** Payment provider configuration. Secrets are read only from the environment/.env file. */
return [
    'flow' => (string) Env::get('PAYMENT_FLOW', 'manual'),
    'driver' => (string) Env::get('PAYMENT_DRIVER', 'manual'),
    'selcom' => [
        'vendor_id' => (string) Env::get('SELCOM_VENDOR_ID', ''),
        'api_key' => (string) Env::get('SELCOM_API_KEY', ''),
        'api_secret' => (string) Env::get('SELCOM_API_SECRET', ''),
        'base_url' => (string) Env::get('SELCOM_BASE_URL', ''),
        'environment' => (string) Env::get('SELCOM_ENVIRONMENT', 'sandbox'),
        'currency' => (string) Env::get('SELCOM_CURRENCY', 'TZS'),
        'timeout_seconds' => (int) Env::get('SELCOM_TIMEOUT_SECONDS', 15),
    ],
];
