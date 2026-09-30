<?php

use PHPUnit\Framework\TestCase;

/** Middleware that blocks every request, to prove middleware runs before the handler. */
final class RouterTestBlockingMiddleware
{
    public function handle(Request $request): void
    {
        throw ApiException::unauthenticated();
    }
}

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $show_product = fn (Request $request) => Response::success(['id' => $request->param('id')]);

        $this->router = new Router();
        $this->router->group('/v1', function (Router $router) use ($show_product) {
            $router->get('/products/{id}', $show_product);
            $router->get('/private', $show_product, [RouterTestBlockingMiddleware::class]);
        });
    }

    public function testMatchesRouteAndPassesParams(): void
    {
        $response = $this->router->dispatch(new Request('GET', '/v1/products/42/'));

        $this->assertSame(200, $response->status());
        $this->assertSame(['success' => true, 'data' => ['id' => '42']], $response->body());
    }

    public function testUnknownPathIs404(): void
    {
        $this->expectExceptionObject(ApiException::notFound('Anwani hii ya API haipo.', 'ROUTE_NOT_FOUND'));
        $this->router->dispatch(new Request('GET', '/v1/nothing'));
    }

    public function testWrongMethodIs405(): void
    {
        try {
            $this->router->dispatch(new Request('POST', '/v1/products/42'));
            $this->fail('Expected 405');
        } catch (ApiException $exception) {
            $this->assertSame(405, $exception->status());
        }
    }

    public function testMiddlewareRunsBeforeHandler(): void
    {
        try {
            $this->router->dispatch(new Request('GET', '/v1/private'));
            $this->fail('Expected 401');
        } catch (ApiException $exception) {
            $this->assertSame(401, $exception->status());
        }
    }

    public function testPaginatedResponseMeta(): void
    {
        $response = Response::paginated([['id' => 1]], 41, 2, 20);

        $this->assertSame(['page' => 2, 'per_page' => 20, 'total' => 41, 'last_page' => 3], $response->body()['meta']);
    }
}
