<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Core\Testing\FakeHealthCheck;
use Hydra\Http\HealthMiddleware;
use Hydra\Http\Responder;
use Hydra\Http\Testing\FakeHandler;
use Hydra\Log\Testing\CapturingLogger;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The probe answers itself, and says which dependency is down but never why.
 */
#[CoversClass(HealthMiddleware::class)]
final class HealthMiddlewareTest extends TestCase
{
    private FakeHandler $handler;

    private CapturingLogger $log;

    protected function setUp(): void
    {
        $this->handler = FakeHandler::respondingWith((new Psr17Factory)->createResponse(200));
        $this->log = new CapturingLogger;
    }

    public function test_every_check_passing_is_a_200_naming_each(): void
    {
        $response = $this->probe([FakeHealthCheck::passing('database'), FakeHealthCheck::passing('cache')]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('{"status":"ok","checks":{"database":"ok","cache":"ok"}}', (string) $response->getBody());
        $this->assertSame([], $this->handler->requests(), 'the probe never reaches the app');
    }

    public function test_one_check_failing_is_a_503_and_the_rest_still_run(): void
    {
        $cache = FakeHealthCheck::passing('cache');

        $response = $this->probe([FakeHealthCheck::failing('database', 'SQLSTATE[HY000] [2002] db:3306 refused'), $cache]);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('{"status":"down","checks":{"database":"down","cache":"ok"}}', (string) $response->getBody());
        $this->assertSame(1, $cache->runs());
    }

    public function test_the_reason_goes_to_the_log_and_not_the_body(): void
    {
        $response = $this->probe([FakeHealthCheck::failing('database', 'db:3306 refused')]);

        $this->assertStringNotContainsString('3306', (string) $response->getBody());
        $record = $this->log->firstWith('Health check database failed: db:3306 refused');
        $this->assertSame(LogLevel::WARNING, $record['level']);
        $this->assertInstanceOf(RuntimeException::class, $record['context']['exception'] ?? null);
    }

    public function test_no_checks_is_a_process_that_answers(): void
    {
        $response = $this->probe([]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"ok","checks":{}}', (string) $response->getBody());
    }

    public function test_head_is_answered_too(): void
    {
        $this->assertSame(200, $this->probe([], 'HEAD')->getStatusCode());
        $this->assertSame([], $this->handler->requests());
    }

    public function test_other_paths_and_methods_pass_through(): void
    {
        $check = FakeHealthCheck::passing('database');
        $middleware = $this->middleware([$check]);
        $factory = new Psr17Factory;

        $middleware->process($factory->createServerRequest('GET', '/upload'), $this->handler);
        $middleware->process($factory->createServerRequest('POST', '/up'), $this->handler);

        $this->assertCount(2, $this->handler->requests());
        $this->assertSame(0, $check->runs());
    }

    public function test_the_path_can_be_moved(): void
    {
        $middleware = $this->middleware([], '/healthz');
        $factory = new Psr17Factory;

        $moved = $middleware->process($factory->createServerRequest('GET', '/healthz'), $this->handler);
        $middleware->process($factory->createServerRequest('GET', '/up'), $this->handler);

        $this->assertSame('{"status":"ok","checks":{}}', (string) $moved->getBody());
        $this->assertCount(1, $this->handler->requests());
    }

    public function test_two_checks_with_one_name_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Two health checks are both named "database".');

        $this->middleware([FakeHealthCheck::passing('database'), FakeHealthCheck::failing('database')]);
    }

    /** @param list<FakeHealthCheck> $checks */
    private function probe(array $checks, string $method = 'GET'): ResponseInterface
    {
        return $this->middleware($checks)
            ->process((new Psr17Factory)->createServerRequest($method, '/up'), $this->handler);
    }

    /** @param list<FakeHealthCheck> $checks */
    private function middleware(array $checks, string $path = HealthMiddleware::PATH): HealthMiddleware
    {
        $factory = new Psr17Factory;

        return new HealthMiddleware(new Responder($factory, $factory), $checks, $this->log, $path);
    }
}
