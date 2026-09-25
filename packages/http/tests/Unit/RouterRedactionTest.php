<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Http\CompiledRoute;
use Hydra\Http\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class TokenController
{
    public function accept(#[\SensitiveParameter] string $token): void {}

    public function show(string $id): void {}

    public function both(string $id, #[\SensitiveParameter] string $token): void {}

    public function pair(#[\SensitiveParameter] string $a, #[\SensitiveParameter] string $b): void {}
}

final class InvokableTokenController
{
    public function __invoke(#[\SensitiveParameter] string $token): void {}
}

final class InvokableShowController
{
    public function __invoke(string $id): void {}
}

/**
 * A path parameter its target marks #[\SensitiveParameter] leaves the path as
 * its placeholder, so a token in a link never reaches a log line.
 */
#[CoversClass(Router::class)]
#[CoversClass(CompiledRoute::class)]
final class RouterRedactionTest extends TestCase
{
    public function test_a_sensitive_parameter_is_masked(): void
    {
        $router = $this->router()->get('/reset-password/{token}', [TokenController::class, 'accept']);

        $this->assertSame('/reset-password/{token}', $router->redact($this->request('GET', '/reset-password/abc123')));
    }

    public function test_a_parameter_nobody_marked_is_left_alone(): void
    {
        $router = $this->router()->get('/users/{id}', [TokenController::class, 'show']);

        $this->assertSame('/users/42/', $router->redact($this->request('GET', '/users/42/')));
    }

    public function test_only_the_marked_segment_is_masked_and_the_rest_reads_as_sent(): void
    {
        $router = $this->router()->get('/teams/{id}/invite/{token}', [TokenController::class, 'both']);

        $this->assertSame('/teams/a%20b/invite/{token}', $router->redact($this->request('GET', '/teams/a%20b/invite/s3cret')));
    }

    public function test_several_marked_segments_are_all_masked(): void
    {
        $router = $this->router()->get('/x/{a}/{b}', [TokenController::class, 'pair']);

        $this->assertSame('/x/{a}/{b}', $router->redact($this->request('GET', '/x/long-first-value/2')));
    }

    public function test_closures_and_invokable_controllers_are_read_too(): void
    {
        $router = $this->router()
            ->get('/a/{token}', fn (#[\SensitiveParameter] string $token) => null)
            ->get('/b/{token}', InvokableTokenController::class)
            ->get('/c/{token}', new InvokableTokenController)
            ->get('/d/{id}', InvokableShowController::class)
            ->get('/e/{id}', new InvokableShowController);

        $this->assertSame('/a/{token}', $router->redact($this->request('GET', '/a/x')));
        $this->assertSame('/b/{token}', $router->redact($this->request('GET', '/b/x')));
        $this->assertSame('/c/{token}', $router->redact($this->request('GET', '/c/x')));
        $this->assertSame('/d/7', $router->redact($this->request('GET', '/d/7')));
        $this->assertSame('/e/7', $router->redact($this->request('GET', '/e/7')));
    }

    public function test_the_route_is_chosen_by_method_as_well_as_path(): void
    {
        $router = $this->router()
            ->post('/links/{token}', [TokenController::class, 'accept'])
            ->get('/links/{token}', [TokenController::class, 'show']);

        $this->assertSame('/links/{token}', $router->redact($this->request('POST', '/links/x')));
        $this->assertSame('/links/x', $router->redact($this->request('GET', '/links/x')));
        $this->assertSame('/links/x', $router->redact($this->request('HEAD', '/links/x')));
    }

    public function test_an_unmatched_path_is_returned_as_it_arrived(): void
    {
        $router = $this->router()->get('/reset-password/{token}', [TokenController::class, 'accept']);

        $this->assertSame('/nowhere/', $router->redact($this->request('GET', '/nowhere/')));
    }

    public function test_a_target_that_cannot_be_read_masks_every_parameter(): void
    {
        $router = $this->router()
            ->get('/users/{id}/{token}', ['Missing\\Controller', 'accept'])
            ->get('/other/{id}', 42);

        $this->assertSame('/users/{id}/{token}', $router->redact($this->request('GET', '/users/1/x')));
        $this->assertSame('/other/{id}', $router->redact($this->request('GET', '/other/1')));
    }

    private function router(): Router
    {
        return new Router($this->createStub(ContainerInterface::class));
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest($method, 'http://x.test' . $path);
    }
}
