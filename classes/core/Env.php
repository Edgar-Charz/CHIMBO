<?php

/**
 * Reads settings from the .env file.
 *
 * Usage:
 *   Env::load(BASE_PATH . '/.env');     // once, in bootstrap.php
 *   Env::get('DB_HOST', '127.0.0.1');   // anywhere
 *
 * "true" / "false" / "null" are converted to real PHP values.
 */
class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('.env file not found. Copy .env.example to .env and fill in the values.');
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            // Skip comments and lines without "="
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // A real environment variable wins over the file (phpunit.xml uses this to pick the test database)
            if (getenv($key) !== false) {
                self::$values[$key] = getenv($key);
                continue;
            }

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                // Quoted value: take what is inside the quotes
                $quote = $value[0];
                $end   = strpos($value, $quote, 1);
                $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
            } elseif (str_starts_with($value, '#')) {
                // Only a comment after "=" ("KEY=   # comment") means the value is empty
                $value = '';
            } else {
                // Unquoted value: remove an inline comment ("value   # comment")
                $comment_start = strpos($value, ' #');
                if ($comment_start !== false) {
                    $value = rtrim(substr($value, 0, $comment_start));
                }
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$values)) {
            return $default;
        }

        $value = self::$values[$key];

        return match (strtolower($value)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => $value,
        };
    }

    /** Changes a setting while the script runs (used by tests, e.g. to pretend to be production). */
    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    /** Like get(), but stops the app if the setting is missing or empty. */
    public static function required(string $key): string
    {
        $value = self::$values[$key] ?? '';
        if ($value === '') {
            throw new RuntimeException("Missing required setting {$key} in .env");
        }
        return $value;
    }
}
