<?php

/**
 * Writes one line per event to storage/logs/app-YYYY-MM-DD.log.
 * Every line carries the request id, which is also sent to the client in the X-Request-Id header,
 * so a user's error report can be matched to the exact log line.
 *
 * Usage: Logger::error('Payment webhook failed', ['payment_id' => 12]);
 */
class Logger
{
    private static string $request_id = '-';

    public static function setRequestId(string $id): void
    {
        self::$request_id = $id;
    }

    public static function requestId(): string
    {
        return self::$request_id;
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /** Logs an exception with its location (and trace when debugging). */
    public static function exception(Throwable $exception, array $context = []): void
    {
        $context += [
            'exception' => get_class($exception),
            'file'      => $exception->getFile() . ':' . $exception->getLine(),
        ];
        if (Env::get('APP_DEBUG', false)) {
            $context['trace'] = $exception->getTraceAsString();
        }
        self::write('ERROR', $exception->getMessage(), $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s %s: %s%s\n",
            gmdate('Y-m-d H:i:s'),
            self::$request_id,
            $level,
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );

        $file = BASE_PATH . '/storage/logs/app-' . gmdate('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX); // logging must never break the request
    }
}
