<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Hub\Frame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The exact bytes on the wire: what EventSource and nginx see. */
#[CoversClass(Frame::class)]
final class FrameTest extends TestCase
{
    public function test_the_stream_preamble(): void
    {
        $this->assertSame(
            "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/event-stream\r\n"
            . "Cache-Control: no-store\r\n"
            . "X-Accel-Buffering: no\r\n"
            . "\r\n"
            . "retry: 3000\n"
            . ": connected\n\n",
            Frame::preamble(),
        );
    }

    public function test_an_event_is_named_by_its_topic_and_carries_the_broadcast(): void
    {
        $this->assertSame(
            "event: module.users\ndata: {\"event\":\"changed\",\"data\":{\"id\":7,\"n\":\"a\\nb/é\"}}\n\n",
            Frame::event(new Envelope('module.users', 'changed', ['id' => 7, 'n' => "a\nb/é"], 1)),
        );
    }

    public function test_empty_data_is_an_object(): void
    {
        $this->assertSame(
            "event: demo\ndata: {\"event\":\"changed\",\"data\":{}}\n\n",
            Frame::event(new Envelope('demo', 'changed', [], 1)),
        );
    }

    public function test_the_heartbeat_and_the_resync(): void
    {
        $this->assertSame(": ping\n\n", Frame::ping());
        $this->assertSame("event: hub.resync\ndata: {}\n\n", Frame::resync());
    }

    public function test_the_refusals_close_the_connection(): void
    {
        $this->assertSame(
            "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n",
            Frame::refuse(204),
        );
        $this->assertSame(
            "HTTP/1.1 403 Forbidden\r\nContent-Type: text/plain\r\nContent-Length: 10\r\nConnection: close\r\n\r\nForbidden\n",
            Frame::refuse(403),
        );
        $this->assertSame(
            "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain\r\nContent-Length: 20\r\nConnection: close\r\nRetry-After: 5\r\n\r\nService Unavailable\n",
            Frame::refuse(503),
        );
        $this->assertStringStartsWith("HTTP/1.1 404 Not Found\r\n", Frame::refuse(404));
        $this->assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", Frame::refuse(400));
    }
}
