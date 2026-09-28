<?php

/**
 * Everything about the incoming HTTP request, in one object:
 * method, path, query string (?page=2), body (JSON or form), headers, uploaded files,
 * route parameters ({id}) and — after authentication — the logged-in user.
 */
class Request
{
    private array $params = [];
    private ?array $user = null;

    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $body = [],
        private array $headers = [],
        private array $files = [],
        private string $ip = ''
    ) {
        $this->method  = strtoupper($method);
        $this->path    = '/' . trim($path, '/');
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /**
     * Builds the Request from PHP's globals.
     * $basePath is the URL folder of the entry file (e.g. "/chimbo/api"); it is removed from the path,
     * so "/chimbo/api/v1/health" becomes "/v1/health".
     */
    public static function fromGlobals(string $basePath): self
    {
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uriPath = rawurldecode($uriPath);
        if ($basePath !== '' && str_starts_with($uriPath, $basePath)) {
            $uriPath = substr($uriPath, strlen($basePath));
        }

        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        // Apache sometimes hides the Authorization header from PHP; recover it
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($auth !== null && !isset(array_change_key_case($headers)['authorization'])) {
            $headers['Authorization'] = $auth;
        }

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $uriPath,
            $_GET,
            self::readBody($headers),
            $headers,
            $_FILES,
            $_SERVER['REMOTE_ADDR'] ?? ''
        );
    }

    /** JSON body for JSON requests, $_POST for forms and file uploads. */
    private static function readBody(array $headers): array
    {
        $contentType = strtolower(array_change_key_case($headers)['content-type'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (trim($raw) === '') {
                return [];
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw ApiException::badRequest('INVALID_JSON', 'Taarifa zilizotumwa si JSON sahihi.');
            }
            return $data;
        }

        return $_POST;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** A value from the query string, e.g. query('page', 1). */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** A value from the request body, e.g. input('phone'). */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** The whole body (for validation). */
    public function all(): array
    {
        return $this->body;
    }

    /** Query string + body together (body wins on duplicate keys). */
    public function allWithQuery(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** The token from "Authorization: Bearer <token>", or null. */
    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }

    public function file(string $name): ?array
    {
        return $this->files[$name] ?? null;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    /** Preferred language from Accept-Language: 'sw' (default) or 'en'. */
    public function locale(): string
    {
        $lang = strtolower(substr((string) $this->header('Accept-Language', 'sw'), 0, 2));
        return $lang === 'en' ? 'en' : 'sw';
    }

    // --- Set by the router and middleware -----------------------------------

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /** A route parameter, e.g. param('id') for /products/{id}. */
    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    /** The logged-in user (set by AuthMiddleware), or null. */
    public function user(): ?array
    {
        return $this->user;
    }
}
