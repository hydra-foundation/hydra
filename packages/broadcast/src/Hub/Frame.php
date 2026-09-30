<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Hydra\Broadcast\Envelope;

/**
 * The bytes the hub writes.
 *
 * A stream's response has no length and is not chunked: its body ends when
 * the connection closes, which is what an event stream is. Each event is
 * named by its topic, so a page listens with `sse:module.users`, and the
 * broadcast's own event name travels in the data beside what it carried.
 */
final class Frame
{
    private const REASONS = [
        204 => 'No Content',
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        503 => 'Service Unavailable',
    ];

    private function __construct() {}

    public static function preamble(): string
    {
        return "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/event-stream\r\n"
            . "Cache-Control: no-store\r\n"
            // nginx would otherwise hold events back to fill its buffer.
            . "X-Accel-Buffering: no\r\n"
            . "\r\n"
            // How long EventSource waits before reconnecting, in milliseconds.
            . "retry: 3000\n"
            . ": connected\n\n";
    }

    public static function event(Envelope $envelope): string
    {
        // JSON has no raw newline in it, so one data line holds it all.
        $data = json_encode(
            ['event' => $envelope->event, 'data' => $envelope->data === [] ? new \stdClass : $envelope->data],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return "event: {$envelope->topic}\ndata: {$data}\n\n";
    }

    /** A comment line: EventSource ignores it, and every proxy between sees traffic. */
    public static function ping(): string
    {
        return ": ping\n\n";
    }

    /** Events may have been missed while Redis was away: pages should fetch what they show again. */
    public static function resync(): string
    {
        return "event: hub.resync\ndata: {}\n\n";
    }

    /** @param 204|400|403|404|503 $status */
    public static function refuse(int $status): string
    {
        $reason = self::REASONS[$status];

        if ($status === 204) {
            return "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n";
        }

        $body = "{$reason}\n";

        return "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: text/plain\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . ($status === 503 ? "Retry-After: 5\r\n" : '')
            . "\r\n"
            . $body;
    }
}
