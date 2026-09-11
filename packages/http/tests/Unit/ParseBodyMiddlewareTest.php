<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Exceptions\BadRequestException;
use Hydra\Http\Input;
use Hydra\Http\ParseBodyMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class ParseBodyMiddlewareTest extends TestCase
{
    private ParseBodyMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new ParseBodyMiddleware;
    }

    public function test_parses_a_json_post_body(): void
    {
        $request = $this->request('POST', 'application/json', '{"name":"cog","qty":2}');

        $seen = $this->passedThrough($request);

        $this->assertSame(['name' => 'cog', 'qty' => 2], $seen->getParsedBody());
    }

    public function test_parses_a_json_put_body_and_input_reads_it(): void
    {
        $request = $this->request('PUT', 'application/json', '{"name":"cog"}');

        $input = Input::fromRequest($this->passedThrough($request));

        $this->assertSame('cog', $input->string('name'));
    }

    public function test_parses_a_urlencoded_put_body(): void
    {
        $request = $this->request('PUT', 'application/x-www-form-urlencoded', 'name=cog&qty=2');

        $this->assertSame(['name' => 'cog', 'qty' => '2'], $this->passedThrough($request)->getParsedBody());
    }

    public function test_matches_json_regardless_of_charset_parameter(): void
    {
        $request = $this->request('POST', 'application/json; charset=utf-8', '{"a":1}');

        $this->assertSame(['a' => 1], $this->passedThrough($request)->getParsedBody());
    }

    public function test_matches_json_suffix_media_types(): void
    {
        $request = $this->request('POST', 'application/vnd.api+json', '{"a":1}');

        $this->assertSame(['a' => 1], $this->passedThrough($request)->getParsedBody());
    }

    public function test_malformed_json_throws_a_bad_request(): void
    {
        $request = $this->request('POST', 'application/json', '{"broken":');

        $this->expectException(BadRequestException::class);
        $this->middleware->process($request, $this->handler());
    }

    public function test_an_empty_json_typed_body_is_absent_not_malformed(): void
    {
        $request = $this->request('POST', 'application/json', '');

        $this->assertNull($this->passedThrough($request)->getParsedBody());
    }

    public function test_a_json_scalar_is_valid_but_not_a_body_map(): void
    {
        $request = $this->request('POST', 'application/json', '"just a string"');

        $this->assertNull($this->passedThrough($request)->getParsedBody());
    }

    public function test_never_clobbers_an_already_parsed_body(): void
    {
        // A POST form the PSR-7 layer already parsed from PHP's globals.
        $request = $this->request('POST', 'application/json', '{"hijack":true}')
            ->withParsedBody(['from' => 'globals']);

        $this->assertSame(['from' => 'globals'], $this->passedThrough($request)->getParsedBody());
    }

    public function test_ignores_get_and_head(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $request = $this->request($method, 'application/json', '{"a":1}');

            $this->assertNull($this->passedThrough($request)->getParsedBody());
        }
    }

    public function test_ignores_unhandled_content_types(): void
    {
        $request = $this->request('PUT', 'text/plain', 'raw text');

        $this->assertNull($this->passedThrough($request)->getParsedBody());
    }

    public function test_rewinds_the_body_stream_for_downstream_readers(): void
    {
        $request = $this->request('POST', 'application/json', '{"a":1}');

        $seen = $this->passedThrough($request);

        $this->assertSame('{"a":1}', (string) $seen->getBody()->getContents());
    }

    public function test_a_non_seekable_body_still_parses_without_throwing(): void
    {
        // PSR-7 permits non-seekable streams; the middleware must skip the
        // downstream-courtesy rewind for those rather than blow up the request.
        $factory = new Psr17Factory;
        $stream = new class ($factory->createStream('{"a":1}')) implements StreamInterface {
            public function __construct(private StreamInterface $inner) {}

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new RuntimeException('Stream is not seekable');
            }

            public function rewind(): void
            {
                $this->seek(0);
            }

            public function __toString(): string { return (string) $this->inner; }
            public function close(): void { $this->inner->close(); }
            public function detach() { return $this->inner->detach(); }
            public function getSize(): ?int { return $this->inner->getSize(); }
            public function tell(): int { return $this->inner->tell(); }
            public function eof(): bool { return $this->inner->eof(); }
            public function isWritable(): bool { return false; }
            public function write(string $string): int { throw new RuntimeException('read-only'); }
            public function isReadable(): bool { return true; }
            public function read(int $length): string { return $this->inner->read($length); }
            public function getContents(): string { return $this->inner->getContents(); }
            public function getMetadata(?string $key = null) { return $this->inner->getMetadata($key); }
        };

        $request = $factory->createServerRequest('POST', '/widgets')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        $this->assertSame(['a' => 1], $this->passedThrough($request)->getParsedBody());
    }

    private function request(string $method, string $contentType, string $body): ServerRequestInterface
    {
        $factory = new Psr17Factory;

        return $factory->createServerRequest($method, '/widgets')
            ->withHeader('Content-Type', $contentType)
            ->withBody($factory->createStream($body));
    }

    /** Run the middleware and capture the request the inner handler received. */
    private function passedThrough(ServerRequestInterface $request): ServerRequestInterface
    {
        $handler = $this->handler();
        $this->middleware->process($request, $handler);

        return $handler->seen;
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ServerRequestInterface $seen;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return (new Psr17Factory)->createResponse(200);
            }
        };
    }
}
