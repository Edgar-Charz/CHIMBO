<?php

use PHPUnit\Framework\TestCase;

final class SelcomGatewayTest extends TestCase
{
    public function testMissingCredentialsFailWithoutExposingTheirValues(): void
    {
        $gateway = new SelcomGateway(['selcom' => [
            'vendor_id' => '', 'api_key' => '', 'api_secret' => '', 'base_url' => '', 'environment' => 'sandbox',
        ]]);

        try {
            $gateway->assertReady();
            $this->fail('Expected unconfigured gateway to fail closed.');
        } catch (ApiException $exception) {
            $this->assertSame(503, $exception->status());
            $this->assertSame('SELCOM_NOT_CONFIGURED', $exception->errorCode());
            $this->assertStringNotContainsString('api_secret', $exception->getMessage());
        }
    }

    public function testConfiguredGatewayStillWaitsForOfficialProviderSpecifications(): void
    {
        $gateway = new SelcomGateway(['selcom' => [
            'vendor_id' => 'placeholder', 'api_key' => 'placeholder', 'api_secret' => 'placeholder',
            'base_url' => 'placeholder', 'environment' => 'sandbox',
        ]]);

        try {
            $gateway->assertReady();
            $this->fail('Expected unfinished provider integration to fail closed.');
        } catch (ApiException $exception) {
            $this->assertSame('SELCOM_INTEGRATION_PENDING', $exception->errorCode());
        }
    }

    public function testUnimplementedWebhookVerificationAlwaysRejects(): void
    {
        $gateway = new SelcomGateway(['selcom' => []]);
        $this->assertFalse($gateway->verifyWebhook(['status' => 'success'], ['X-Signature' => 'forged']));
    }

    public function testMalformedWebhookPayloadIsRejected(): void
    {
        $this->expectException(ApiException::class);
        (new PaymentWebhookHandler(new SelcomGateway(['selcom' => []])))->process('{', []);
    }

    public function testRepeatedUnverifiedWebhookCannotChangePaymentState(): void
    {
        $handler = new PaymentWebhookHandler(new SelcomGateway(['selcom' => []]));
        foreach ([1, 2] as $attempt) {
            try {
                $handler->process('{"status":"success"}', ['X-Signature' => 'forged']);
                $this->fail('Expected each unverified callback attempt to be rejected.');
            } catch (ApiException $exception) {
                $this->assertSame('UNAUTHENTICATED', $exception->errorCode());
            }
        }
    }
}
