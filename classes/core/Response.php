<?php

/**
 * A JSON response in CHIMBO's standard format.
 *
 * Success:  { "success": true,  "data": ..., "meta": {...} }
 * Error:    { "success": false, "error": { "code": "...", "message": "...", "fields": {...} } }
 *
 * Controllers return a Response; api/index.php sends it.
 * For a file (e.g. a PDF receipt) use Response::download() instead.
 */
class Response
{
    // JSON answers at least this big are gzip-compressed when the client accepts it
    private const COMPRESS_FROM_BYTES = 1024;

    private array $headers = [
        'Content-Type'  => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
    ];

    // The raw bytes of a download (null for normal JSON answers)
    private ?string $file_content = null;

    public function __construct(private array $body, private int $status = 200)
    {
    }

    /** A file download, e.g. Response::download($pdf_bytes, 'application/pdf', 'Risiti-CHB123456.pdf'). */
    public static function download(string $file_content, string $content_type, string $file_name): self
    {
        $response = new self([]);
        $response->file_content = $file_content;
        $response->headers['Content-Type'] = $content_type;
        $response->headers['Content-Disposition'] = 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $file_name) . '"';
        $response->headers['Content-Length'] = (string) strlen($file_content);
        return $response;
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
        if ($this->file_content !== null) {
            $this->sendHeaders();
            echo $this->file_content;
            return;
        }

        $json = json_encode(
            $this->body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );

        // Compress bigger answers when the app/browser accepts it: JSON shrinks by ~70–80%,
        // which matters on mobile data. Tiny answers aren't worth compressing.
        if (strlen($json) >= self::COMPRESS_FROM_BYTES && $this->clientAcceptsGzip()) {
            $json = gzencode($json, 6);
            $this->headers['Content-Encoding'] = 'gzip';
        }
        $this->headers['Vary'] = 'Accept-Encoding';

        $this->sendHeaders();
        echo $json;
    }

    private function sendHeaders(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
    }

    private function clientAcceptsGzip(): bool
    {
        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip')
            && ini_get('zlib.output_compression') !== '1'; // never compress twice
    }
}
