<?php

/** Checks accepted DARSMS messages and saves their latest delivery receipts. */
class SmsDeliveryStatusSync
{
    public function __construct(
        private Database $db,
        private ?DarsmsSmsGateway $gateway = null
    ) {}

    /** Checks up to 100 messages, spacing repeat checks by five minutes. */
    public function sync(): int
    {
        $messages = $this->db->fetchAll(
            'SELECT sms_id, sms_provider_message_id
             FROM sms_outbox
             WHERE sms_status IN (:queued_status, :sent_status)
               AND sms_provider_message_id IS NOT NULL
               AND created_at <= UTC_TIMESTAMP() - INTERVAL 1 MINUTE
               AND (sms_status_checked_at IS NULL
                    OR sms_status_checked_at <= UTC_TIMESTAMP() - INTERVAL 5 MINUTE)
             ORDER BY sms_id ASC
             LIMIT 100',
            ['queued_status' => 'queued', 'sent_status' => 'sent']
        );

        $gateway = $this->gateway ?? new DarsmsSmsGateway();
        foreach ($messages as $message) {
            $sms_id = (int) $message['sms_id'];
            try {
                $status = $gateway->getMessageStatus((string) $message['sms_provider_message_id']);
                $this->db->execute(
                    'UPDATE sms_outbox
                     SET sms_status = :sms_status,
                         sms_sent_at = IF(:has_been_sent, COALESCE(sms_sent_at, UTC_TIMESTAMP()), NULL),
                         sms_status_checked_at = UTC_TIMESTAMP()
                     WHERE sms_id = :sms_id',
                    [
                        'sms_status' => $status,
                        'has_been_sent' => (int) in_array($status, ['sent', 'delivered'], true),
                        'sms_id' => $sms_id,
                    ]
                );
            } catch (Throwable $exception) {
                Logger::exception($exception, ['sms_id' => $sms_id, 'operation' => 'delivery_status']);
                $this->db->execute(
                    'UPDATE sms_outbox SET sms_status_checked_at = UTC_TIMESTAMP() WHERE sms_id = :sms_id',
                    ['sms_id' => $sms_id]
                );
            }
        }

        return count($messages);
    }
}
