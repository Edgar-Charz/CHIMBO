<?php

/**
 * Everything about the incoming request, in one object, so API files never read $_GET/$_POST directly.
 *
 * How to use it (inside an API file):
 *   $request->input('user_phone')   // a value sent in the body (JSON or form)
 *   $request->all()                 // the whole body, e.g. to pass to a class method
 *   $request->query('page', 1)      // a value from the URL: ?page=2
 *   $request->param('id')           // a value from the route: /products/{id}
 *   $request->bearerToken()         // the login token sent by the mobile app
 *   $request->user()                // the logged-in user (after AuthMiddleware)
 */
class Request
{
    // Values taken from the route, e.g. ['id' => '42'] for /products/42
    private array $params = [];

    // The logged-in user, set by AuthMiddleware (null for guests)
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
        $this->method  = strtoupper($method);                         // "post" → "POST"
        $this->path    = '/' . trim($path, '/');                       // "/v1/health/" → "/v1/health"
        $this->headers = array_change_key_case($headers, CASE_LOWER);  // header names are case-insensitive
    }

    /**
     * Builds the Request from PHP's $_SERVER, $_GET, $_POST and $_FILES.
     * $base_path is the folder of api/index.php in the URL ("/chimbo/api"); it is removed,
     * so "/chimbo/api/v1/health" becomes "/v1/health".
     */
    public static function fromGlobals(string $base_path): self
    {
        // 1. The path, without the "?..." part and without the base folder
        $uri_path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        if ($base_path !== '' && str_starts_with($uri_path, $base_path)) {
            $uri_path = substr($uri_path, strlen($base_path));
        }

        // 2. The headers. Apache sometimes hides "Authorization" from PHP, so we recover it
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $auth    = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($auth !== null && !isset(array_change_key_case($headers)['authorization'])) {
            $headers['Authorization'] = $auth;
        }

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $uri_path,
            $_GET,
            self::readBody($headers),
            $headers,
            $_FILES,
            $_SERVER['REMOTE_ADDR'] ?? ''
        );
    }

    /** The mobile app sends JSON; the website's forms and file uploads arrive in $_POST. */
    private static function readBody(array $headers): array
    {
        $content_type = strtolower(array_change_key_case($headers)['content-type'] ?? '');

        if (!str_contains($content_type, 'application/json')) {
            return $_POST;
        }

        $raw_body = file_get_contents('php://input');
        if (trim($raw_body) === '') {
            return [];
        }

        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            throw ApiException::badRequest('INVALID_JSON', 'Taarifa zilizotumwa si JSON sahihi.');
        }
        return $data;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** A value from the URL query string, e.g. query('page', 1) for ?page=2. */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** The whole URL query string (?page=2&sort=newest), e.g. for list filters. */
    public function allQuery(): array
    {
        return $this->query;
    }

    /** A value from the request body, e.g. input('user_phone'). */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** The whole request body. */
    public function all(): array
    {
        return $this->body;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** The login token from the header "Authorization: Bearer <token>", or null. */
    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /** An uploaded file (the $_FILES entry), e.g. file('avatar'). */
    public function file(string $name): ?array
    {
        return $this->files[$name] ?? null;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    /** The user's language from the Accept-Language header: 'sw' (default) or 'en'. */
    public function locale(): string
    {
        $language = strtolower(substr((string) $this->header('Accept-Language', 'sw'), 0, 2));
        return $language === 'en' ? 'en' : 'sw';
    }

    // --- Filled in by the Router and AuthMiddleware -------------------------

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /** A value from the route, e.g. param('id') for /products/{id}. */
    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    /** The logged-in user, or null for guests. */
    public function user(): ?array
    {
        return $this->user;
    }
}
