<?php

/**
 * Maps "METHOD /path" to a function that handles the request.
 *
 * In an API file (api/endpoints/*.php):
 *   $router->get('/products/{id}', function (Request $request) {
 *       $product = new Product(Database::instance());
 *       return Response::success($product->getProductById((int) $request->param('id')));
 *   });
 *
 *   $router->post('/cart/items', function (Request $request) { ... }, [AuthMiddleware::class]);
 *
 * Middleware classes have a handle(Request $request): void method and run before the handler.
 * They stop the request by throwing an ApiException (e.g. 401).
 */
class Router
{
    private array $routes = [];
    private string $prefix = '';
    private array $group_middleware = [];

    public function get(string $path, Closure $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, Closure $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function patch(string $path, Closure $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    public function put(string $path, Closure $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, Closure $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /** Routes defined inside $define_routes share a path prefix and middleware. Groups can be nested. */
    public function group(string $prefix, callable $define_routes, array $middleware = []): void
    {
        $previous_prefix     = $this->prefix;
        $previous_middleware = $this->group_middleware;

        $this->prefix          = $previous_prefix . '/' . trim($prefix, '/');
        $this->group_middleware = array_merge($previous_middleware, $middleware);

        $define_routes($this);

        $this->prefix          = $previous_prefix;
        $this->group_middleware = $previous_middleware;
    }

    private function addRoute(string $method, string $path, Closure $handler, array $middleware): void
    {
        // Group prefix + path, e.g. "/v1" + "/products/{id}" → "/v1/products/{id}"
        $full_path = '/' . trim($this->prefix . '/' . trim($path, '/'), '/');

        // Turn every {name} into a regex part that captures that piece of the URL:
        // "/v1/products/{id}" → "/v1/products/(?P<id>[^/]+)", so "/v1/products/42" gives id = "42"
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $full_path);

        $this->routes[] = [
            'method'     => $method,
            'path'       => $full_path,
            'pattern'    => '#^' . $pattern . '$#',
            'handler'    => $handler,
            'middleware' => array_merge($this->group_middleware, $middleware),
        ];
    }

    /** Finds the matching route, runs its middleware and handler, and returns the Response. */
    public function dispatch(Request $request): Response
    {
        // Remembers if the path exists with another method (to answer 405 instead of 404)
        $path_exists = false;

        foreach ($this->routes as $route) {
            // 1. Does the URL match this route's pattern?
            if (!preg_match($route['pattern'], $request->path(), $matches)) {
                continue;
            }
            $path_exists = true;

            // 2. Is it the right method (GET, POST …)?
            if ($route['method'] !== $request->method()) {
                continue;
            }

            // 3. Save the {params}; $matches also has numbered entries, we keep only the named ones
            $request->setParams(array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));

            // 4. Run the middleware (e.g. login check) — it throws an error to stop the request
            foreach ($route['middleware'] as $middleware_class) {
                (new $middleware_class())->handle($request); 
            }

            // 5. Run the endpoint's function and return its Response
            $response = ($route['handler'])($request);

            if (!$response instanceof Response) {
                throw new LogicException("The handler for {$route['method']} {$route['path']} must return a Response");
            }
            return $response;
        }

        if ($path_exists) {
            throw ApiException::methodNotAllowed();
        }
        throw ApiException::notFound('Anwani hii ya API haipo.', 'ROUTE_NOT_FOUND');
    }

    /** All registered routes, e.g. "GET /v1/health" (useful for debugging and tests). */
    public function listRoutes(): array
    {
        return array_map(fn (array $route) => $route['method'] . ' ' . $route['path'], $this->routes);
    }
}
