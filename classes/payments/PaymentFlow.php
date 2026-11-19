<?php

/** Selects the customer payment flow shared by API clients. */
class PaymentFlow
{
    private const SUPPORTED_FLOWS = ['manual', 'selcom'];

    private static ?array $configuration = null;

    public static function current(): string
    {
        self::$configuration ??= require BASE_PATH . '/config/payment.php';
        $flow = strtolower(trim((string) (self::$configuration['flow'] ?? 'manual')));
        if (!in_array($flow, self::SUPPORTED_FLOWS, true)) {
            throw new ApiException(503, 'PAYMENT_FLOW_INVALID', 'Mipangilio ya malipo haijakamilika.');
        }

        return $flow;
    }

    public static function assertManualEnabled(): void
    {
        if (self::current() !== 'manual') {
            throw ApiException::conflict('PAYMENT_FLOW_CHANGED', 'Tumia kitufe cha Lipa sasa kwa oda hii.');
        }
    }

    public static function assertSelcomEnabled(): void
    {
        if (self::current() !== 'selcom') {
            throw ApiException::conflict('PAYMENT_FLOW_MANUAL', 'Malipo ya Selcom hayajachaguliwa kwa sasa.');
        }
    }
}
