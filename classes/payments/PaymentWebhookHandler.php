<?php

/** Rejects notifications until Selcom's official authentication and payload contract are implemented. */
class PaymentWebhookHandler
{
    public function __construct(private PaymentGatewayInterface $gateway)
    {
    }

    public function process(string $raw_body, array $headers): void
    {
        $payload = json_decode($raw_body, true);
        if (!is_array($payload) || $payload === []) {
            throw ApiException::badRequest('INVALID_WEBHOOK', 'Taarifa za malipo haziko sahihi.');
        }
        if (!$this->gateway->verifyWebhook($payload, $headers)) {
            throw ApiException::unauthenticated('Uhalisi wa taarifa ya malipo haujathibitishwa.');
        }

        // Payload mapping, event identity, amount/currency checks and state transitions require Selcom's specification.
        throw new ApiException(503, 'SELCOM_WEBHOOK_PENDING', 'Uthibitishaji wa taarifa za Selcom unasubiri maelekezo rasmi ya API.');
    }
}
