<?php

/** Provider contract. Provider-specific request and signature rules stay inside each gateway. */
interface PaymentGatewayInterface
{
    /** Fail before recording an attempt when required credentials or official provider details are missing. */
    public function assertReady(): void;

    /** Start a provider transaction and return normalized, non-secret result fields. */
    public function initiatePayment(array $payment): array;

    /** Query a provider transaction by its provider reference. */
    public function checkPaymentStatus(string $provider_reference): array;

    /** Normalize a provider response without treating transport success as payment success. */
    public function interpretResponse(array $response): array;

    /** Extract the provider's stable transaction reference from a normalized response. */
    public function getProviderReference(array $response): ?string;

    /** Validate callback authenticity using the provider's official specification. */
    public function verifyWebhook(array $payload, array $headers): bool;
}
