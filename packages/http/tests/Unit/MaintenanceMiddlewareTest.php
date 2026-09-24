<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Exceptions\ServiceUnavailableException;
use Hydra\Http\Maintenance;
use Hydra\Http\MaintenanceMiddleware;
use Hydra\Http\NegotiatingErrorRenderer;
use Hydra\Http\Responder;
use Hydra\Http\Testing\FakeHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Down means a 503 for every request, in whatever form the client asked for,
 * with the message the operator gave and Retry-After when they gave one.
 */
#[CoversClass(MaintenanceMiddleware::class)]
#[CoversClass(ServiceUnavailableException::class)]
final class MaintenanceMiddlewareTest extends TestCase
{
    private string $path;

    private Maintenance $maintenance;

    private FakeHandler $handler;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/hydra-maintenance-' . bin2hex(random_bytes(4)) . '.json';
        $this->maintenance = new Maintenance($this->path);
        $this->handler = FakeHandler::respondingWith((new Psr17Factory)->createResponse(200));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_up_passes_through(): void
    {
        $this->assertSame(200, $this->send()->getStatusCode());
        $this->assertCount(1, $this->handler->requests());
    }

    public function test_down_is_a_503_that_never_reaches_the_app(): void
    {
        $this->maintenance->down('Upgrading the database.', 300);

        $response = $this->send('application/json');

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('300', $response->getHeaderLine('Retry-After'));
        $this->assertSame(
            ['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503, 'detail' => 'Upgrading the database.'],
            json_decode((string) $response->getBody(), true),
        );
        $this->assertSame([], $this->handler->requests());
    }

    public function test_without_a_retry_there_is_no_retry_after(): void
    {
        $this->maintenance->down();

        $response = $this->send('text/html');

        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Retry-After'));
        $this->assertStringContainsString(Maintenance::DEFAULT_MESSAGE, (string) $response->getBody());
    }

    public function test_retry_after_is_whole_seconds_and_never_below_one(): void
    {
        $this->assertSame(['Retry-After' => '1'], (new ServiceUnavailableException('x', 0))->headers());
        $this->assertSame(['Retry-After' => '2'], (new ServiceUnavailableException('x', 2))->headers());
        $this->assertSame([], (new ServiceUnavailableException)->headers());
    }

    public function test_back_up_serves_again(): void
    {
        $this->maintenance->down();
        $this->maintenance->up();

        $this->assertSame(200, $this->send()->getStatusCode());
    }

    private function send(string $accept = '*/*'): ResponseInterface
    {
        $factory = new Psr17Factory;
        $middleware = new MaintenanceMiddleware(
            $this->maintenance,
            new NegotiatingErrorRenderer(new Responder($factory, $factory)),
        );

        return $middleware->process(
            $factory->createServerRequest('GET', '/')->withHeader('Accept', $accept),
            $this->handler,
        );
    }
}
