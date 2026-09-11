<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Http\Exceptions\MethodNotAllowedException;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/** A controller resolved from the container; records the request it received. */
final class FooController
{
    public ?ServerRequestInterface $received = null;

    public function __construct(private readonly ResponseInterface $response) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->received = $request;
        return $this->response;
    }
}

final class RouterTest extends TestCase
{
    private function request(string $method, string $path): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('withAttribute')->willReturnSelf();

        return $request;
    }

    private function container(array $map = []): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $map[$id] ?? null);

        return $container;
    }

    public function testMatchedRouteReturnsHandlerResponse(): void
    {
        $expected = $this->createStub(ResponseInterface::class);
        $router = new Router($this->container());
        $router->get('/health', fn (): ResponseInterface => $expected);

        $response = $router->handle($this->request('GET', '/health'));

        $this->assertSame($expected, $response);
    }

    public function testUnknownPathThrowsNotFound(): void
    {
        $router = new Router($this->container());
        $router->get('/health', fn (): ResponseInterface => $this->createStub(ResponseInterface::class));

        $this->expectException(NotFoundException::class);
        $router->handle($this->request('GET', '/nope'));
    }

    public function testKnownPathWrongMethodThrowsMethodNotAllowedWithAllowedList(): void
    {
        $router = new Router($this->container());
        $router->get('/users', fn (): ResponseInterface => $this->createStub(ResponseInterface::class));
        $router->post('/users', fn (): ResponseInterface => $this->createStub(ResponseInterface::class));

        try {
            $router->handle($this->request('DELETE', '/users'));
            $this->fail('expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(405, $e->status());
            $this->assertSame(['Allow' => 'GET, POST'], $e->headers());
        }
    }

    public function testResolvesControllerFromContainerAndInvokesMethod(): void
    {
        $expected = $this->createStub(ResponseInterface::class);
        $controller = new FooController($expected);
        $request = $this->request('GET', '/foo');

        $router = new Router($this->container([FooController::class => $controller]));
        $router->get('/foo', [FooController::class, 'show']);

        $response = $router->handle($request);

        $this->assertSame($expected, $response, 'returns the controller method response');
        $this->assertSame($request, $controller->received, 'controller method receives the request');
    }

    public function testTrailingSlashIsNormalized(): void
    {
        $expected = $this->createStub(ResponseInterface::class);
        $router = new Router($this->container());
        $router->get('/health', fn (): ResponseInterface => $expected);

        // Request path has a trailing slash; route was registered without one.
        $response = $router->handle($this->request('GET', '/health/'));

        $this->assertSame($expected, $response);
    }

    public function testRouteParamsAreAttachedAsRequestAttributes(): void
    {
        $expected = $this->createStub(ResponseInterface::class);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/users/42');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->expects($this->once())
            ->method('withAttribute')
            ->with('id', '42')
            ->willReturnSelf();

        $router = new Router($this->container());
        $router->get('/users/{id}', fn (): ResponseInterface => $expected);

        $this->assertSame($expected, $router->handle($request));
    }

    public function testTypedRouteParamIsBoundToTheHandlerArgument(): void
    {
        $expected = $this->createStub(ResponseInterface::class);
        $seen = null;

        $router = new Router($this->container());
        $router->get('/users/{id}', function (int $id) use (&$seen, $expected): ResponseInterface {
            $seen = $id;
            return $expected;
        });

        $response = $router->handle($this->request('GET', '/users/42'));

        $this->assertSame($expected, $response);
        $this->assertSame(42, $seen, 'the {id} segment is coerced to int and bound by name');
    }

    public function testNonCoercibleParamFallsThroughToNotFound(): void
    {
        // /users/abc can't satisfy `int $id`, so the URL addresses no resource.
        $router = new Router($this->container());
        $router->get('/users/{id}', fn (int $id): ResponseInterface => $this->createStub(ResponseInterface::class));

        $this->expectException(NotFoundException::class);
        $router->handle($this->request('GET', '/users/abc'));
    }

    public function testVerbIsPartOfMatching(): void
    {
        $router = new Router($this->container());
        $router->get('/thing', fn (): ResponseInterface => $this->createStub(ResponseInterface::class));

        // A POST to the same path must not hit the GET handler — it's a 405.
        $this->expectException(MethodNotAllowedException::class);
        $router->handle($this->request('POST', '/thing'));
    }

    public function testHeadRequestMatchesAGetRoute(): void
    {
        // RFC 9110 §9.3.2: HEAD must work wherever GET does (curl -I, health
        // checks, link checkers). Body-stripping is the SAPI/Emitter's job.
        $expected = $this->createStub(ResponseInterface::class);
        $router = new Router($this->container());
        $router->get('/health', fn (): ResponseInterface => $expected);

        $this->assertSame($expected, $router->handle($this->request('HEAD', '/health')));
    }

    public function testHeadOnANonGetRouteStillThrowsMethodNotAllowed(): void
    {
        // HEAD falls back to GET only — a path with no GET handler is still 405.
        $router = new Router($this->container());
        $router->post('/submit', fn (): ResponseInterface => $this->createStub(ResponseInterface::class));

        try {
            $router->handle($this->request('HEAD', '/submit'));
            $this->fail('expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(['Allow' => 'POST'], $e->headers());
        }
    }

    public function testExplicitHeadRouteIsMatchedForHeadRequests(): void
    {
        $expected = $this->createStub(ResponseInterface::class);
        $router = new Router($this->container());
        $router->add('HEAD', '/ping', fn (): ResponseInterface => $expected);

        $this->assertSame($expected, $router->handle($this->request('HEAD', '/ping')));
    }
}
