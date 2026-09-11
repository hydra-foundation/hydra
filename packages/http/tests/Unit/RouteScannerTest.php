<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Attributes\RouteGroup;
use Hydra\Http\Router;
use Hydra\Http\RouteScanner;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/** Marker middleware class-strings — the scanner only stores them, never resolves them. */
final class GroupMiddleware {}
final class MethodMiddleware {}

/**
 * Controller under a class-level group: every method route inherits the prefix
 * and the group middleware (outermost), with method middleware appended.
 */
#[RouteGroup('/admin', middleware: [GroupMiddleware::class])]
final class AdminPanelController
{
    #[Route('/')] // the group's root → "/admin", not "/admin/"
    public function index(): void {}

    #[Route('/users')]
    public function users(): void {}

    #[Route('/users/{id}', methods: ['POST'], middleware: [MethodMiddleware::class])]
    public function update(): void {}
}

/** A prefix written without a leading slash, and with a trailing one. */
#[RouteGroup('admin/')]
final class SloppyPrefixController
{
    #[Route('/users')]
    public function users(): void {}
}

/** Grouped purely to share middleware — the default empty prefix leaves paths alone. */
#[RouteGroup(middleware: [GroupMiddleware::class])]
final class MiddlewareOnlyGroupController
{
    #[Route('/reports')]
    public function reports(): void {}
}

/** Misuse: #[RouteGroup] is not repeatable. */
#[RouteGroup('/a')]
#[RouteGroup('/b')]
final class DoublyGroupedController
{
    #[Route('/x')]
    public function x(): void {}
}

/** Controller exercising defaults, multiple verbs, repeats, and a non-route method. */
final class BlogController
{
    public ?string $called = null;

    public function __construct(private readonly ResponseInterface $response) {}

    #[Route('/posts', methods: ['GET'])]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = 'index';
        return $this->response;
    }

    #[Route('/posts/{id}')] // methods default to GET
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = 'show';
        return $this->response;
    }

    #[Route('/posts', methods: ['POST'])]
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = 'store';
        return $this->response;
    }

    #[Route('/feed')]
    #[Route('/feed.rss')]
    public function feed(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = 'feed';
        return $this->response;
    }

    public function notARoute(): void {}
}

final class RouteScannerTest extends TestCase
{
    /** Normalize a scanned route to "METHOD path => Class::method" for set comparison. */
    private function fingerprint(array $route): string
    {
        [$class, $method] = $route['handler'];
        return "{$route['method']} {$route['path']} => {$class}::{$method}";
    }

    public function testScanEmitsOneRoutePerVerbPerAttributeAndIgnoresPlainMethods(): void
    {
        $routes = (new RouteScanner)->scan([BlogController::class]);

        $actual = array_map($this->fingerprint(...), $routes);
        sort($actual);

        $expected = [
            'GET /feed => ' . BlogController::class . '::feed',
            'GET /feed.rss => ' . BlogController::class . '::feed',
            'GET /posts => ' . BlogController::class . '::index',
            'GET /posts/{id} => ' . BlogController::class . '::show',
            'POST /posts => ' . BlogController::class . '::store',
        ];
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    public function testRouteGroupPrependsPrefixAndCollapsesRootSlash(): void
    {
        $routes = (new RouteScanner)->scan([AdminPanelController::class]);

        $paths = array_map(fn (array $r): string => $r['path'], $routes);
        sort($paths);

        // The group root "/" joins to "/admin" (no trailing slash), not "/admin/".
        $this->assertSame(['/admin', '/admin/users', '/admin/users/{id}'], $paths);
    }

    public function testRouteGroupMiddlewareIsOutermostThenMethodMiddleware(): void
    {
        $routes = (new RouteScanner)->scan([AdminPanelController::class]);
        $byPath = [];
        foreach ($routes as $route) {
            $byPath[$route['path']] = $route['middleware'];
        }

        // A plain grouped route carries only the group's middleware.
        $this->assertSame([GroupMiddleware::class], $byPath['/admin']);

        // Group middleware runs outermost; the method's own middleware follows.
        $this->assertSame(
            [GroupMiddleware::class, MethodMiddleware::class],
            $byPath['/admin/users/{id}'],
        );
    }

    public function testRouteGroupCanonicalizesAPrefixMissingItsLeadingSlash(): void
    {
        $routes = (new RouteScanner)->scan([SloppyPrefixController::class]);

        // The emitted (cacheable) path is canonical regardless of how the prefix
        // was written — no reliance on the Router normalizing it again later.
        $this->assertSame('/admin/users', $routes[0]['path']);
    }

    public function testEmptyPrefixGroupLeavesPathsVerbatimButStillFoldsMiddleware(): void
    {
        $routes = (new RouteScanner)->scan([MiddlewareOnlyGroupController::class]);

        $this->assertSame('/reports', $routes[0]['path']);
        $this->assertSame([GroupMiddleware::class], $routes[0]['middleware']);
    }

    public function testDuplicateRouteGroupFailsLoud(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('only one is allowed');

        (new RouteScanner)->scan([DoublyGroupedController::class]);
    }

    public function testGroupedRoutesStayPlainAndCacheable(): void
    {
        $routes = (new RouteScanner)->scan([AdminPanelController::class]);

        // The cacheable-array guarantee must hold once middleware is a populated
        // list rather than the empty default — that's the regression-prone path.
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $this->assertSame(['method', 'path', 'handler', 'middleware'], array_keys($route));
        }
        $this->assertSame($routes, unserialize(serialize($routes)));
    }

    public function testRoutesWithoutAGroupKeepRawPathsAndNoMiddleware(): void
    {
        $routes = (new RouteScanner)->scan([BlogController::class]);

        $paths = array_map(fn (array $r): string => $r['path'], $routes);
        sort($paths);

        // No group attribute → paths are emitted verbatim, middleware stays empty.
        $this->assertSame(['/feed', '/feed.rss', '/posts', '/posts', '/posts/{id}'], $paths);
        foreach ($routes as $route) {
            $this->assertSame([], $route['middleware']);
        }
    }

    public function testScannedArrayIsPlainAndCacheable(): void
    {
        $routes = (new RouteScanner)->scan([BlogController::class]);

        // Each entry is a plain array: serializable, so it can be cached to disk.
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $this->assertSame(['method', 'path', 'handler', 'middleware'], array_keys($route));
            $this->assertIsString($route['method']);
            $this->assertIsString($route['path']);
            $this->assertSame([BlogController::class, $route['handler'][1]], $route['handler']);
            $this->assertIsArray($route['middleware']);
        }
        $this->assertSame($routes, unserialize(serialize($routes)));
    }

    public function testScannedRoutesDispatchThroughTheRouter(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $controller = new BlogController($response);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($controller);

        $router = new Router($container);
        $router->loadRoutes((new RouteScanner)->scan([BlogController::class]));

        $this->assertSame($response, $router->handle($this->request('POST', '/posts')));
        $this->assertSame('store', $controller->called);
    }

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
}
