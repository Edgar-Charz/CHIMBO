<?php

/**
 * Sends SMS through the provider chosen in .env (SMS_DRIVER) and records each one in `sms_outbox`.
 *
 * How to use it:
 *   $sms_sender = new SmsSender(Database::instance());
 *   $sms_sender->send('+255712345678', 'Oda yako imetumwa.', 'order_status');
 */
class SmsSender
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Sends the SMS now. Throws a 503 error if it cannot be sent.
     * $message_to_store: what is saved in sms_outbox — pass a hidden version for secret codes,
     * so login codes are never stored in plain text.
     */
    public function send(string $phone, string $message, string $purpose, ?string $message_to_store = null): void
    {
        $sms_id = $this->db->insert(
            'INSERT INTO sms_outbox (sms_phone, sms_message, sms_purpose) VALUES (:sms_phone, :sms_message, :sms_purpose)',
            ['sms_phone' => $phone, 'sms_message' => $message_to_store ?? $message, 'sms_purpose' => $purpose]
        );

        try {
            $provider_message_id = $this->gateway()->send($phone, $message);
        } catch (Throwable $e) {
            Logger::exception($e, ['sms_id' => $sms_id]);
            $this->markSms($sms_id, 'failed');
            throw new ApiException(503, 'SMS_FAILED', 'Imeshindikana kutuma SMS. Tafadhali jaribu tena baada ya muda mfupi.');
        }

        $this->markSms($sms_id, 'sent', $provider_message_id);
    }

    /** The provider class for SMS_DRIVER. Add one line here for each real provider. */
    private function gateway(): SmsGateway
    {
        $driver = Env::get('SMS_DRIVER', 'log');

        return match ($driver) {
            'log'   => new LogSmsGateway(),
            default => throw new LogicException("Unknown SMS_DRIVER in .env: {$driver}"),
        };
    }

    private function markSms(int $sms_id, string $status, ?string $provider_message_id = null): void
    {
        $this->db->execute(
            'UPDATE sms_outbox
             SET sms_status = :sms_status,
                 sms_attempts = sms_attempts + 1,
                 sms_provider_message_id = :provider_message_id,
                 sms_sent_at = IF(:is_sent, UTC_TIMESTAMP(), NULL)
             WHERE sms_id = :sms_id',
            [
                'sms_status'          => $status,
                'provider_message_id' => $provider_message_id,
                'is_sent'             => (int) ($status === 'sent'),
                'sms_id'              => $sms_id,
            ]
        );
    }
}
