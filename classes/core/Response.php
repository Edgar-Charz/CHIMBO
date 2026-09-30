<?php

/**
 * A JSON response in CHIMBO's standard format.
 *
 * Success:  { "success": true,  "data": ..., "meta": {...} }
 * Error:    { "success": false, "error": { "code": "...", "message": "...", "fields": {...} } }
 *
 * Controllers return a Response; api/index.php sends it.
 */
class Response
{
    private array $headers = [
        'Content-Type'  => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
    ];

    public function __construct(private array $body, private int $status = 200)
    {
    }

    public static function success(mixed $data = null, int $status = 200, ?array $meta = null): self
    {
        $body = ['success' => true, 'data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }
        return new self($body, $status);
    }

    /** 201 Created. */
    public static function created(mixed $data = null): self
    {
        return self::success($data, 201);
    }

    /** A page of a longer list, with pagination details in "meta". */
    public static function paginated(array $items, int $total, int $page, int $per_page): self
    {
        return self::success($items, 200, [
            'page'      => $page,
            'per_page'  => $per_page,
            'total'     => $total,
            'last_page' => max(1, (int) ceil($total / max(1, $per_page))),
        ]);
    }

    public static function error(string $code, string $message, int $status, array $fields = [], ?array $debug = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields) {
            $error['fields'] = $fields;
        }
        if ($debug !== null) {
            $error['debug'] = $debug; // only when APP_DEBUG=true
        }
        return new self(['success' => false, 'error' => $error], $status);
    }

    public static function fromException(ApiException $exception): self
    {
        return self::error($exception->errorCode(), $exception->getMessage(), $exception->status(), $exception->fields());
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): array
    {
        return $this->body;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo json_encode(
            $this->body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
