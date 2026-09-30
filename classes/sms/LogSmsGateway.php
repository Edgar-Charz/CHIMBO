<?php

/**
 * Development "SMS provider": writes messages to storage/logs/sms-YYYY-MM-DD.log instead of sending them.
 * Used when SMS_DRIVER=log in .env.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): ?string
    {
        $line = sprintf("[%s] to %s: %s\n", gmdate('Y-m-d H:i:s'), $phone, $message);
        file_put_contents(BASE_PATH . '/storage/logs/sms-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);

        return null;
    }
}
