<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Hub\RequestHead;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The only request the hub serves is a GET of /stream with a token; anything else is read just far enough to refuse. */
#[CoversClass(RequestHead::class)]
final class RequestHeadTest extends TestCase
{
    public function test_a_head_is_complete_only_at_the_blank_line(): void
    {
        $this->assertFalse(RequestHead::complete("GET /stream?token=a HTTP/1.1\r\nHost: x\r\n"));
        $this->assertTrue(RequestHead::complete("GET /stream?token=a HTTP/1.1\r\nHost: x\r\n\r\n"));
    }

    public function test_a_stream_request_with_its_token(): void
    {
        $head = RequestHead::parse("GET /stream?token=abc-_1 HTTP/1.1\r\nHost: x\r\nAccept: text/event-stream\r\n\r\n");

        $this->assertNotNull($head);
        $this->assertTrue($head->isStream());
        $this->assertSame('abc-_1', $head->token());
    }

    public function test_an_encoded_token_is_decoded(): void
    {
        $head = RequestHead::parse("GET /stream?a=1&token=ab%2Dc HTTP/1.1\r\n\r\n");

        $this->assertSame('ab-c', $head?->token());
    }

    public function test_no_token_or_an_array_token_is_none(): void
    {
        $this->assertNull(RequestHead::parse("GET /stream HTTP/1.1\r\n\r\n")?->token());
        $this->assertNull(RequestHead::parse("GET /stream?token[]=a HTTP/1.1\r\n\r\n")?->token());
        $this->assertNull(RequestHead::parse("GET /stream?token= HTTP/1.1\r\n\r\n")?->token());
    }

    public function test_another_path_or_method_is_not_a_stream(): void
    {
        $this->assertFalse(RequestHead::parse("GET /streams?token=a HTTP/1.1\r\n\r\n")?->isStream());
        $this->assertFalse(RequestHead::parse("GET /stream/x?token=a HTTP/1.1\r\n\r\n")?->isStream());
        $this->assertFalse(RequestHead::parse("POST /stream?token=a HTTP/1.1\r\n\r\n")?->isStream());
    }

    public function test_a_request_line_that_is_not_http_is_null(): void
    {
        $this->assertNull(RequestHead::parse("hello\r\n\r\n"));
        $this->assertNull(RequestHead::parse("GET /stream\r\n\r\n"));
        $this->assertNull(RequestHead::parse("GET stream HTTP/1.1\r\n\r\n"));
    }
}
