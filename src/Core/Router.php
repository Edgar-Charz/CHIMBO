<?php

/**
 * Maps "METHOD /path" to a controller method.
 *
 * In routes/api.php:
 *   $router->group('/v1', function (Router $r) {
 *       $r->get('/health', [HealthController::class, 'show']);
 *       $r->get('/products/{id}', [ProductController::class, 'show']);
 *       $r->post('/cart/items', [CartController::class, 'add'], [AuthMiddleware::class]);
 *   });
 *
 * Middleware classes have a handle(Request $request): void method and run before the controller.
 * They stop the request by throwing an ApiException (e.g. 401).
 */
class Router
{
    private array $routes = [];
    private string $prefix = '';
    private array $groupMiddleware = [];

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function patch(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** Routes inside $define share a path prefix and middleware. Groups can be nested. */
    public function group(string $prefix, callable $define, array $middleware = []): void
    {
        [$oldPrefix, $oldMiddleware] = [$this->prefix, $this->groupMiddleware];

        $this->prefix          = $oldPrefix . '/' . trim($prefix, '/');
        $this->groupMiddleware = array_merge($oldMiddleware, $middleware);

        $define($this);

        [$this->prefix, $this->groupMiddleware] = [$oldPrefix, $oldMiddleware];
    }

    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $fullPath = '/' . trim($this->prefix . '/' . trim($path, '/'), '/');

        // "/products/{id}" → regex with a named group: #^/products/(?P<id>[^/]+)$#
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $fullPath);

        $this->routes[] = [
            'method'     => $method,
            'path'       => $fullPath,
            'regex'      => '#^' . $regex . '$#',
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    /** Finds the matching route, runs its middleware and controller, returns the Response. */
    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path(), $matches)) {
                continue;
            }
            $pathMatched = true;

            if ($route['method'] !== $request->method()) {
                continue;
            }

            // Keep only the named {params}
            $request->setParams(array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));

            foreach ($route['middleware'] as $middlewareClass) {
                (new $middlewareClass())->handle($request);
            }

            [$controllerClass, $action] = $route['handler'];
            $response = (new $controllerClass())->$action($request);

            if (!$response instanceof Response) {
                throw new LogicException("{$controllerClass}::{$action} must return a Response");
            }
            return $response;
        }

        if ($pathMatched) {
            throw ApiException::methodNotAllowed();
        }
        throw ApiException::notFound('Anwani hii ya API haipo.', 'ROUTE_NOT_FOUND');
    }

    /** All registered routes (useful for debugging and tests). */
    public function routes(): array
    {
        return array_map(fn ($r) => $r['method'] . ' ' . $r['path'], $this->routes);
    }
}
