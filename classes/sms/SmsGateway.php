<?php

/**
 * What every SMS provider class must be able to do.
 * Today: LogSmsGateway (development). Later: one class per real provider (Beem, NextSMS …),
 * chosen with SMS_DRIVER in .env — nothing else in the code changes.
 */
interface SmsGateway
{
    /**
     * Sends one SMS. Returns the provider's message id (or null if it gives none).
     * Throws an exception if the provider refuses or cannot be reached.
     */
    public function send(string $phone, string $message): ?string;
}
