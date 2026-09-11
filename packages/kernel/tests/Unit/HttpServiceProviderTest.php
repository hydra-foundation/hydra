<?php

declare(strict_types=1);

namespace Hydra\Kernel\Tests\Unit;

use Hydra\Core\Contracts\KernelInterface;
use Hydra\Http\Contracts\EmitterInterface;
use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\Responder;
use Hydra\Http\RouteCache;
use Hydra\Http\Router;
use Hydra\Kernel\HttpServiceProvider;
use Hydra\Kernel\Tests\Support\StubController;
use Hydra\Kernel\Tests\Support\TestContainer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Proves the extracted plumbing wires together: registering the provider binds
 * the whole HTTP chain, and a request actually routes through the pipeline to a
 * controller. This is what used to live (and drift) in each app's
 * AppServiceProvider.
 *
 * The provider deliberately binds no PSR-7 vendor — the PSR-17 factories and
 * the request provider are seams the composition root fills (nyholm's
 * NyholmServiceProvider in the reference app). These tests fill them with
 * local doubles, which is itself proof the kernel is vendor-free.
 */
final class HttpServiceProviderTest extends TestCase
{
    private TestContainer $container;

    protected function setUp(): void
    {
        $this->container = new TestContainer;
        // The strict container has no autowiring, so pre-bind the one class the
        // router resolves; the provider registers everything else.
        $this->container->instance(StubController::class, new StubController);

        // Fill the PSR-7 seams the way a composition root would (nyholm here is
        // a dev-only test fixture, not a kernel dependency).
        $factory = new Psr17Factory;
        $this->container->instance(ResponseFactoryInterface::class, $factory);
        $this->container->instance(StreamFactoryInterface::class, $factory);
        $this->container->instance(ServerRequestProviderInterface::class, new class ($factory) implements ServerRequestProviderInterface {
            public function __construct(private readonly Psr17Factory $factory) {}

            public function fromGlobals(): ServerRequestInterface
            {
                return $this->factory->createServerRequest('GET', '/ping');
            }
        });

        (new HttpServiceProvider(
            controllers: [StubController::class],
            middleware: [],
            routeCacheEnabled: false,
            routeCachePath: '/nonexistent/routes.php',
        ))->register($this->container);
    }

    public function test_binds_the_whole_http_chain(): void
    {
        $this->assertInstanceOf(EmitterInterface::class, $this->container->get(EmitterInterface::class));
        $this->assertInstanceOf(Responder::class, $this->container->get(Responder::class));
        $this->assertInstanceOf(RouteCache::class, $this->container->get(RouteCache::class));
        $this->assertInstanceOf(Router::class, $this->container->get(Router::class));
        $this->assertInstanceOf(RequestHandlerInterface::class, $this->container->get(RequestHandlerInterface::class));
        $this->assertInstanceOf(KernelInterface::class, $this->container->get(KernelInterface::class));
    }

    public function test_binds_the_default_error_renderer(): void
    {
        // The ErrorRendererInterface seam resolves out of the box to the plain-text
        // default, so the ErrorHandlerMiddleware works with zero app wiring; an app
        // rebinds it to render HTML/htmx/JSON instead.
        $this->assertInstanceOf(
            PlainTextErrorRenderer::class,
            $this->container->get(ErrorRendererInterface::class),
        );
    }

    public function test_the_provider_binds_no_psr7_vendor(): void
    {
        // Without a composition root filling the request-provider seam, the
        // kernel fails loud rather than silently defaulting to a vendor.
        $bare = new TestContainer;
        (new HttpServiceProvider(
            controllers: [],
            middleware: [],
            routeCacheEnabled: false,
            routeCachePath: '/nonexistent/routes.php',
        ))->register($bare);

        $this->assertFalse($bare->bound(ServerRequestProviderInterface::class));
        $this->assertFalse($bare->bound(ResponseFactoryInterface::class));

        $this->expectException(NotFoundExceptionInterface::class);
        $bare->get(KernelInterface::class);
    }

    public function test_the_pipeline_routes_a_request_to_the_controller(): void
    {
        $request = (new Psr17Factory)->createServerRequest('GET', '/ping');

        $response = $this->container->get(RequestHandlerInterface::class)->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pong', (string) $response->getBody());
    }

    public function test_shared_bindings_return_the_same_instance(): void
    {
        // singleton() semantics: the responder a controller sees is the one the
        // middleware sees.
        $this->assertSame(
            $this->container->get(Responder::class),
            $this->container->get(Responder::class),
        );
    }
}
