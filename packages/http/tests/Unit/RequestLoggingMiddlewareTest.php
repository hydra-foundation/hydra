<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Contracts\PathRedactorInterface;
use Hydra\Http\RequestLoggingMiddleware;
use Hydra\Http\Testing\FakeHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;

/**
 * One structured line per request carrying the facts an access log needs, and
 * the inner response handed back untouched.
 */
#[CoversClass(RequestLoggingMiddleware::class)]
final class RequestLoggingMiddlewareTest extends TestCase
{
    public function test_logs_one_line_with_request_and_response_facts(): void
    {
        $logger = new RecordingLogger;
        $request = (new Psr17Factory)->createServerRequest('POST', 'http://x.test/login');

        (new RequestLoggingMiddleware($logger))->process($request, $this->handler(201));

        $this->assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];

        $this->assertSame('info', $level);
        $this->assertSame('request handled', $message);
        $this->assertSame('POST', $context['method']);
        $this->assertSame('/login', $context['path']);
        $this->assertSame(201, $context['status']);
        $this->assertArrayHasKey('duration_ms', $context);
        $this->assertIsFloat($context['duration_ms']);
    }

    public function test_the_path_is_logged_as_the_redactor_writes_it(): void
    {
        $logger = new RecordingLogger;
        $paths = new class implements PathRedactorInterface {
            public function redact(ServerRequestInterface $request): string
            {
                return '/reset-password/{token}';
            }
        };
        $request = (new Psr17Factory)->createServerRequest('GET', '/reset-password/abc123');

        (new RequestLoggingMiddleware($logger, $paths))->process($request, $this->handler(302));

        $this->assertSame('/reset-password/{token}', $logger->records[0][2]['path']);
    }

    public function test_returns_the_inner_response_unchanged(): void
    {
        $response = (new RequestLoggingMiddleware(new RecordingLogger))
            ->process((new Psr17Factory)->createServerRequest('GET', '/'), $this->handler(200));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function handler(int $status): FakeHandler
    {
        return FakeHandler::respondingWith((new Psr17Factory)->createResponse($status));
    }
}

/** A PSR-3 logger that records every call for assertion. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
