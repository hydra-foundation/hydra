<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use ArrayObject;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Http\Attributes\Route as RouteAttribute;
use Hydra\Http\Attributes\RouteGroup as RouteGroupAttribute;
use Hydra\Http\Router;
use Hydra\Http\RouteScanner;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

// ---------------------------------------------------------------------------
// Fixture middleware for per-route tests
// ---------------------------------------------------------------------------

/**
 * A middleware that appends to a shared log and passes through, optionally
 * forwarding a request attribute to the next layer to verify it was visible.
 *
 * Reusing the RecordingMiddleware defined in PipelineTest would require
 * loading that class; a separate, local fixture is cleaner.
 */
final class TaggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $tag,
        private readonly ArrayObject $log,
        private readonly ?ResponseInterface $shortCircuit = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log[] = "enter:{$this->tag}";

        if ($this->shortCircuit !== null) {
            $this->log[] = "short:{$this->tag}";
            return $this->shortCircuit;
        }

        $response = $handler->handle($request);
        $this->log[] = "exit:{$this->tag}";

        return $response;
    }
}

/**
 * A middleware that records which request-attribute value it observed for a
 * given key, then passes through.  Used to assert that route params (set by
 * the Router via withAttribute) are visible inside per-route middleware.
 */
final class AttributeSniffingMiddleware implements MiddlewareInterface
{
    public mixed $observed = null;

    public function __construct(private readonly string $attributeKey) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->observed = $request->getAttribute($this->attributeKey);
        return $handler->handle($request);
    }
}

// ---------------------------------------------------------------------------
// Fixture controller decorated with #[Route] + middleware for scanner tests
// ---------------------------------------------------------------------------

/** Middleware stubs used as class-string tokens in attribute declarations. */
final class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

/** Controller with one route that carries middleware and one without. */
final class AdminController
{
    public function __construct(private readonly ResponseInterface $response) {}

    #[RouteAttribute('/admin', middleware: [AuthMiddleware::class, RateLimitMiddleware::class])]
    public function dashboard(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }

    #[RouteAttribute('/open')]
    public function open(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}

/** Marker class-strings for the grouped-dispatch test; the container maps them to recording middleware. */
final class GroupTagMiddleware {}
final class MethodTagMiddleware {}

/**
 * A grouped controller: the class-level #[RouteGroup] carries the OUTER
 * middleware, the method's #[Route] the INNER one. Used to prove the scanner's
 * group→method fold doesn't just serialize correctly (RouteScannerTest already
 * pins that) but actually EXECUTES outermost-first once dispatched through
 * Router::handle() — the ordering AdminController relies on for its auth/authz
 * gates.
 */
#[RouteGroupAttribute('/admin', middleware: [GroupTagMiddleware::class])]
final class GroupedTaggingController
{
    public function __construct(
        private readonly ArrayObject $log,
        private readonly ResponseInterface $response,
    ) {}

    #[RouteAttribute('/panel', middleware: [MethodTagMiddleware::class])]
    public function panel(): ResponseInterface
    {
        $this->log[] = 'controller';
        return $this->response;
    }
}

// ---------------------------------------------------------------------------
// The test class
// ---------------------------------------------------------------------------

final class PerRouteMiddlewareTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers that mirror RouterTest / RouteScannerTest style exactly
    // ------------------------------------------------------------------

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

    private function response(): ResponseInterface
    {
        return $this->createStub(ResponseInterface::class);
    }

    // ------------------------------------------------------------------
    // 1. Middleware runs around the controller in outermost-first order
    // ------------------------------------------------------------------

    public function testMiddlewareRunsAroundControllerInOutermostFirstOrder(): void
    {
        $log = new ArrayObject;
        $controllerResponse = $this->response();
        $called = false;

        $mwA = new TaggingMiddleware('A', $log);
        $mwB = new TaggingMiddleware('B', $log);

        $router = new Router($this->container([
            TaggingMiddleware::class . '.A' => $mwA,
            TaggingMiddleware::class . '.B' => $mwB,
        ]));

        // Use arbitrary unique class-strings as keys in the container map.
        $classA = 'Fake\MiddlewareA';
        $classB = 'Fake\MiddlewareB';

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use ($classA, $classB, $mwA, $mwB): mixed {
            return match ($id) {
                $classA => $mwA,
                $classB => $mwB,
                default => null,
            };
        });

        $router = new Router($container);
        $router->get('/test', function () use (&$called, $log, $controllerResponse): ResponseInterface {
            $called = true;
            $log[] = 'controller';
            return $controllerResponse;
        }, [$classA, $classB]);

        $response = $router->handle($this->request('GET', '/test'));

        $this->assertSame($controllerResponse, $response);
        $this->assertTrue($called, 'controller must be invoked');
        // A is outermost: enter:A, enter:B, controller, exit:B, exit:A
        $this->assertSame(
            ['enter:A', 'enter:B', 'controller', 'exit:B', 'exit:A'],
            $log->getArrayCopy(),
        );
    }

    // ------------------------------------------------------------------
    // 2. Per-route middleware can short-circuit before reaching the controller
    // ------------------------------------------------------------------

    public function testPerRouteMiddlewareCanShortCircuitWithoutCallingController(): void
    {
        $log = new ArrayObject;
        $shortResponse = $this->response();
        $controllerCalled = false;

        $blocking = new TaggingMiddleware('blocker', $log, shortCircuit: $shortResponse);

        $classBlocker = 'Fake\Blocker';
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $id === $classBlocker ? $blocking : null);

        $router = new Router($container);
        $router->get('/guarded', function () use (&$controllerCalled): ResponseInterface {
            $controllerCalled = true;
            return $this->response();
        }, [$classBlocker]);

        $response = $router->handle($this->request('GET', '/guarded'));

        $this->assertSame($shortResponse, $response);
        $this->assertFalse($controllerCalled, 'controller must not be called after short-circuit');
        $this->assertSame(['enter:blocker', 'short:blocker'], $log->getArrayCopy());
    }

    // ------------------------------------------------------------------
    // 3. Per-route middleware can see route-param request attributes
    // ------------------------------------------------------------------

    public function testPerRouteMiddlewareReceivesRouteParamAttributes(): void
    {
        $sniffer = new AttributeSniffingMiddleware('userId');
        $controllerResponse = $this->response();

        // Build a real request mock that tracks withAttribute calls and returns
        // a copy that also implements getAttribute so the sniffer can read it.
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/users/99');

        // We need getAttribute to work on the request the middleware sees.
        // Use a mock where withAttribute returns a new stub with getAttribute pre-set.
        $enrichedRequest = $this->createStub(ServerRequestInterface::class);
        $enrichedRequest->method('getAttribute')
            ->willReturnCallback(fn (string $name) => $name === 'userId' ? '99' : null);
        // So that the handler can call handle($enrichedRequest) without crashing.
        $enrichedRequest->method('withAttribute')->willReturnSelf();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        // When Router calls withAttribute('userId', '99') it returns the enriched stub.
        $request->method('withAttribute')->willReturn($enrichedRequest);

        $classSniff = 'Fake\Sniffer';
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $id === $classSniff ? $sniffer : null);

        $router = new Router($container);
        $router->get('/users/{userId}', fn (): ResponseInterface => $controllerResponse, [$classSniff]);

        $response = $router->handle($request);

        $this->assertSame($controllerResponse, $response);
        $this->assertSame('99', $sniffer->observed, 'middleware must see the userId route-param attribute');
    }

    // ------------------------------------------------------------------
    // 4. Route with no middleware returns bare handler (no observable wrapping)
    // ------------------------------------------------------------------

    public function testRouteWithNoMiddlewareReturnsDirectControllerResponse(): void
    {
        $expected = $this->response();
        $resolveCount = 0;

        $container = $this->createStub(ContainerInterface::class);
        // get() should never be called — no class-string to resolve.
        $container->method('get')->willReturnCallback(function () use (&$resolveCount) {
            $resolveCount++;
            return null;
        });

        $router = new Router($container);
        $router->get('/plain', fn (): ResponseInterface => $expected);

        $response = $router->handle($this->request('GET', '/plain'));

        $this->assertSame($expected, $response);
        $this->assertSame(0, $resolveCount, 'container must not be called for a route with no middleware');
    }

    // ------------------------------------------------------------------
    // 5a. Middleware is resolved lazily — only on a match
    // ------------------------------------------------------------------

    public function testMiddlewareClassIsNotResolvedForNonMatchingRoute(): void
    {
        $resolvedClasses = [];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use (&$resolvedClasses): mixed {
            $resolvedClasses[] = $id;
            return null;
        });

        $classAuth = 'Fake\AuthMiddlewareForLazyTest';
        $expected = $this->response();

        $router = new Router($container);
        $router->get('/admin', fn (): ResponseInterface => $expected, [$classAuth]);
        // Add a second route that will match — to prove the router still runs.
        $router->get('/open', fn (): ResponseInterface => $expected);

        // Request hits /open, not /admin — the middleware for /admin must never be resolved.
        $router->handle($this->request('GET', '/open'));

        $this->assertNotContains($classAuth, $resolvedClasses, 'middleware for /admin must not be resolved on a /open request');
    }

    // ------------------------------------------------------------------
    // 5b. Middleware IS resolved on a match
    // ------------------------------------------------------------------

    public function testMiddlewareClassIsResolvedThroughContainerOnMatch(): void
    {
        $log = new ArrayObject;
        $controllerResponse = $this->response();
        $classAuth = 'Fake\AuthMiddlewareOnMatch';
        $mw = new TaggingMiddleware('auth', $log);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $id === $classAuth ? $mw : null);

        $router = new Router($container);
        $router->get('/secret', fn (): ResponseInterface => $controllerResponse, [$classAuth]);

        $response = $router->handle($this->request('GET', '/secret'));

        $this->assertSame($controllerResponse, $response);
        $this->assertContains('enter:auth', $log->getArrayCopy(), 'middleware must have been resolved and run');
    }

    // ------------------------------------------------------------------
    // 6a. Scanner captures middleware from #[Route] attribute
    // ------------------------------------------------------------------

    public function testScannerCapturesMiddlewareClassStringsFromAttribute(): void
    {
        $routes = (new RouteScanner)->scan([AdminController::class]);

        $withMiddleware = array_values(array_filter(
            $routes,
            fn (array $r) => $r['path'] === '/admin',
        ));

        $this->assertCount(1, $withMiddleware, 'exactly one route for /admin');
        $this->assertSame(
            [AuthMiddleware::class, RateLimitMiddleware::class],
            $withMiddleware[0]['middleware'],
        );
    }

    public function testScannerEmitsEmptyMiddlewareArrayWhenNoneSpecified(): void
    {
        $routes = (new RouteScanner)->scan([AdminController::class]);

        $openRoute = array_values(array_filter(
            $routes,
            fn (array $r) => $r['path'] === '/open',
        ));

        $this->assertCount(1, $openRoute, 'exactly one route for /open');
        $this->assertSame([], $openRoute[0]['middleware']);
    }

    // ------------------------------------------------------------------
    // 6b. Scanned routes with middleware dispatch correctly end-to-end
    // ------------------------------------------------------------------

    public function testScannedRoutesWithMiddlewareDispatchThroughRouter(): void
    {
        $log = new ArrayObject;
        $controllerResponse = $this->response();
        $controller = new AdminController($controllerResponse);

        $authMw = new TaggingMiddleware('auth', $log);
        $rateMw = new TaggingMiddleware('rate', $log);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => match ($id) {
            AdminController::class => $controller,
            AuthMiddleware::class => $authMw,
            RateLimitMiddleware::class => $rateMw,
            default => null,
        });

        $router = new Router($container);
        $router->loadRoutes((new RouteScanner)->scan([AdminController::class]));

        $response = $router->handle($this->request('GET', '/admin'));

        $this->assertSame($controllerResponse, $response);
        // AuthMiddleware is outermost (first in array), RateLimitMiddleware is inner.
        $this->assertSame(
            ['enter:auth', 'enter:rate', 'exit:rate', 'exit:auth'],
            $log->getArrayCopy(),
            'middleware must run outermost-first around the controller',
        );
    }

    // ------------------------------------------------------------------
    // 6c. loadRoutes propagates middleware to the Route value object
    // ------------------------------------------------------------------

    public function testLoadRoutesFlowsMiddlewareIntoRegisteredRoute(): void
    {
        $log = new ArrayObject;
        $controllerResponse = $this->response();
        $mw = new TaggingMiddleware('loaded', $log);
        $classMw = 'Fake\LoadedMiddleware';

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $id === $classMw ? $mw : null);

        $router = new Router($container);
        $router->loadRoutes([
            [
                'method' => 'GET',
                'path' => '/loaded',
                'handler' => fn (): ResponseInterface => $controllerResponse,
                'middleware' => [$classMw],
            ],
        ]);

        $response = $router->handle($this->request('GET', '/loaded'));

        $this->assertSame($controllerResponse, $response);
        $this->assertContains('enter:loaded', $log->getArrayCopy(), 'middleware passed through loadRoutes must run');
    }

    // ------------------------------------------------------------------
    // 6d. Group middleware executes OUTERMOST of the method's own, end-to-end
    // ------------------------------------------------------------------

    public function testGroupMiddlewareRunsOutermostThroughFullDispatch(): void
    {
        $log = new ArrayObject;
        $controllerResponse = $this->response();
        $controller = new GroupedTaggingController($log, $controllerResponse);

        $container = $this->container([
            GroupedTaggingController::class => $controller,
            GroupTagMiddleware::class => new TaggingMiddleware('group', $log),
            MethodTagMiddleware::class => new TaggingMiddleware('method', $log),
        ]);

        $router = new Router($container);
        $router->loadRoutes((new RouteScanner)->scan([GroupedTaggingController::class]));

        $response = $router->handle($this->request('GET', '/admin/panel'));

        $this->assertSame($controllerResponse, $response);
        // Group middleware wraps the method's: enter group → enter method →
        // controller → exit method → exit group. This is the ordering the
        // AdminController's #[RouteGroup] auth/authz gates depend on.
        $this->assertSame(
            ['enter:group', 'enter:method', 'controller', 'exit:method', 'exit:group'],
            $log->getArrayCopy(),
        );
    }
}
