<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\ClientIpResolver;
use Hydra\Http\RequestId;
use Hydra\Http\RequestIdMiddleware;
use Hydra\Http\Testing\FakeHandler;
use Hydra\Http\TrustedProxies;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One id per request, in three places that must agree, and the rule for when
 * a client-supplied X-Request-Id is adopted rather than replaced.
 */
#[CoversClass(RequestIdMiddleware::class)]
#[CoversClass(RequestId::class)]
final class RequestIdMiddlewareTest extends TestCase
{
    private RequestId $current;
    private FakeHandler $handler;

    protected function setUp(): void
    {
        $this->current = new RequestId;
        $this->handler = FakeHandler::respondingWith((new Psr17Factory)->createResponse(200));
    }

    public function test_attribute_holder_and_header_carry_the_same_generated_id(): void
    {
        $response = (new RequestIdMiddleware($this->current))->process($this->request(), $this->handler);

        $id = $response->getHeaderLine('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertSame($id, $this->current->get());
        $this->assertSame($id, $this->handler->requests()[0]->getAttribute(RequestId::ATTRIBUTE));
    }

    public function test_each_request_gets_a_new_id(): void
    {
        $middleware = new RequestIdMiddleware($this->current);

        $first = $middleware->process($this->request(), $this->handler)->getHeaderLine('X-Request-Id');
        $second = $middleware->process($this->request(), $this->handler)->getHeaderLine('X-Request-Id');

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->current->get());
    }

    public function test_an_incoming_id_is_ignored_unless_trusted(): void
    {
        $response = (new RequestIdMiddleware($this->current))
            ->process($this->request(header: 'from-the-client'), $this->handler);

        $this->assertNotSame('from-the-client', $response->getHeaderLine('X-Request-Id'));
    }

    public function test_a_trusted_incoming_id_is_adopted(): void
    {
        $response = (new RequestIdMiddleware($this->current, trustIncoming: true))
            ->process($this->request(header: '4f1c9a0e2b7d4c1e'), $this->handler);

        $this->assertSame('4f1c9a0e2b7d4c1e', $response->getHeaderLine('X-Request-Id'));
        $this->assertSame('4f1c9a0e2b7d4c1e', $this->current->get());
    }

    public function test_with_proxies_declared_only_their_id_is_adopted(): void
    {
        $middleware = new RequestIdMiddleware(
            $this->current,
            trustIncoming: true,
            clients: new ClientIpResolver(new TrustedProxies(['10.0.0.0/8'])),
        );

        $viaProxy = $middleware->process($this->request('10.0.0.5', '4f1c9a0e2b7d4c1e'), $this->handler);
        $direct = $middleware->process($this->request('203.0.113.9', '4f1c9a0e2b7d4c1e'), $this->handler);

        $this->assertSame('4f1c9a0e2b7d4c1e', $viaProxy->getHeaderLine('X-Request-Id'));
        $this->assertNotSame('4f1c9a0e2b7d4c1e', $direct->getHeaderLine('X-Request-Id'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'spaces' => ['abc def ghi'];
        yield 'json' => ['{"a":"b"}xx'];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_trusted_id_is_replaced(string $header): void
    {
        $response = (new RequestIdMiddleware($this->current, trustIncoming: true))
            ->process($this->request(header: $header), $this->handler);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->getHeaderLine('X-Request-Id'));
    }

    public function test_the_holder_is_empty_before_any_request(): void
    {
        $this->assertNull($this->current->get());
    }

    private function request(?string $peer = null, ?string $header = null): ServerRequestInterface
    {
        $request = (new Psr17Factory)->createServerRequest('GET', '/', $peer === null ? [] : ['REMOTE_ADDR' => $peer]);

        return $header === null ? $request : $request->withHeader('X-Request-Id', $header);
    }
}
