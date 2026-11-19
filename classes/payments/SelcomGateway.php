<?php

/** Selcom adapter boundary. Provider requests remain disabled until official documentation is supplied. */
class SelcomGateway implements PaymentGatewayInterface
{
    private const PROVIDER = 'selcom';

    public function __construct(private ?array $configuration = null)
    {
        $this->configuration ??= require BASE_PATH . '/config/payment.php';
    }

    public function assertReady(): void
    {
        $settings = $this->configuration['selcom'] ?? [];
        $required = ['vendor_id', 'api_key', 'api_secret', 'base_url'];
        foreach ($required as $key) {
            if (trim((string) ($settings[$key] ?? '')) === '') {
                throw new ApiException(503, 'SELCOM_NOT_CONFIGURED', 'Malipo kwa simu hayajasanidiwa bado. Tafadhali jaribu njia nyingine.');
            }
        }
        if (!in_array($settings['environment'] ?? '', ['sandbox', 'production'], true)) {
            throw new ApiException(503, 'SELCOM_CONFIGURATION_INVALID', 'Mipangilio ya malipo haijakamilika.');
        }

        throw new ApiException(503, 'SELCOM_INTEGRATION_PENDING', 'Muunganisho wa Selcom unasubiri maelekezo rasmi ya API.');
    }

    public function initiatePayment(array $payment): array
    {
        $this->assertReady();
        throw new LogicException('Selcom initiation must not run before its official API contract is implemented.');
    }

    public function checkPaymentStatus(string $provider_reference): array
    {
        $this->assertReady();
        throw new LogicException('Selcom status checks must not run before its official API contract is implemented.');
    }

    public function interpretResponse(array $response): array
    {
        return ['payment_status' => 'pending', 'provider_reference' => null, 'redirect_url' => null];
    }

    public function getProviderReference(array $response): ?string
    {
        return null;
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        return false;
    }
}
