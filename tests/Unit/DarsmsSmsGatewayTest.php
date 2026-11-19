<?php

use PHPUnit\Framework\TestCase;

final class DarsmsSmsGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        Env::set('DARSMS_API_KEY', 'test-key');
        Env::set('DARSMS_SENDER_ID', 'CHIMBO');
        Env::set('DARSMS_API_URL', 'https://sms.example.test/send');
    }

    public function testAcceptedResponseIsRecordedAsQueuedAndUsesExpectedPayload(): void
    {
        $request = null;
        $gateway = new DarsmsSmsGateway(function (string $method, string $url, string $api_key, ?string $payload) use (&$request): array {
            $request = [$method, $url, $api_key, json_decode((string) $payload, true)];
            return [
                'status_code' => 202,
                'body' => '{"success":true,"data":{"messageIds":["darsms-123"]}}',
            ];
        });

        $result = $gateway->send('+255712345678', 'Verification message');

        $this->assertSame('queued', $result['status']);
        $this->assertSame('darsms-123', $result['message_id']);
        $this->assertSame([
            'POST',
            'https://sms.example.test/send',
            'test-key',
            ['senderId' => 'CHIMBO', 'to' => ['+255712345678'], 'message' => 'Verification message'],
        ], $request);
    }

    public function testApiLevelRejectionInSuccessfulHttpResponseIsNotRecordedAsQueued(): void
    {
        $gateway = new DarsmsSmsGateway(static fn (): array => [
            'status_code' => 202,
            'body' => '{"success":false,"message":"No credits"}',
        ]);

        $this->expectException(RuntimeException::class);
        $gateway->send('+255712345678', 'Verification message');
    }

    public function testAcceptedResponseWithoutMessageIdIsNotRecordedAsQueued(): void
    {
        $gateway = new DarsmsSmsGateway(static fn (): array => [
            'status_code' => 202,
            'body' => '{"success":true,"data":{"messageIds":[]}}',
        ]);

        $this->expectException(RuntimeException::class);
        $gateway->send('+255712345678', 'Verification message');
    }

    public function testDeliveryStatusUsesProviderReceiptAndMapsTerminalStates(): void
    {
        $request = null;
        $gateway = new DarsmsSmsGateway(function (string $method, string $url, string $api_key, ?string $payload) use (&$request): array {
            $request = [$method, $url, $api_key, $payload];
            return [
                'status_code' => 200,
                'body' => '{"success":true,"data":{"status":"DELIVERED"}}',
            ];
        });

        $this->assertSame('delivered', $gateway->getMessageStatus('darsms-123'));
        $this->assertSame([
            'GET',
            'https://sms.example.test/status/darsms-123',
            'test-key',
            null,
        ], $request);
    }

    public function testNonSuccessResponseFailsWithoutExposingProviderBody(): void
    {
        $gateway = new DarsmsSmsGateway(static fn (): array => [
            'status_code' => 401,
            'body' => 'private provider response',
        ]);

        try {
            $gateway->send('+255712345678', 'Verification message');
            $this->fail('Expected provider rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private provider response', $exception->getMessage());
        }
    }

    public function testDarsmsModeNeverReturnsTheOtpForAutoFill(): void
    {
        $original_app_env = Env::get('APP_ENV', 'production');
        $original_sms_driver = Env::get('SMS_DRIVER', 'log');
        $original_show_code = Env::get('OTP_SHOW_CODE', false);

        Env::set('APP_ENV', 'local');
        Env::set('SMS_DRIVER', 'darsms');
        Env::set('OTP_SHOW_CODE', 'true');

        $this->assertFalse(CustomerAuth::shouldShowCodeInResponse());

        Env::set('SMS_DRIVER', 'log');
        $this->assertTrue(CustomerAuth::shouldShowCodeInResponse());

        Env::set('APP_ENV', (string) $original_app_env);
        Env::set('SMS_DRIVER', (string) $original_sms_driver);
        Env::set('OTP_SHOW_CODE', (string) $original_show_code);
    }
}
