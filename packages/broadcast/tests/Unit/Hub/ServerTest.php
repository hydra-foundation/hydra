<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Hub\Server;
use Hydra\Broadcast\Hub\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Who gets a stream, and who hears what on it. */
#[CoversClass(Server::class)]
#[CoversClass(Stream::class)]
final class ServerTest extends TestCase
{
    use HubHarness;

    public function test_a_valid_token_opens_a_stream_with_the_preamble(): void
    {
        $browser = $this->browser(self::get($this->tokens->mint(1, ['demo'], 60)));

        $this->assertSame(
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-store\r\nX-Accel-Buffering: no\r\n\r\nretry: 3000\n: connected\n\n",
            $this->drain($browser),
        );
        $this->assertSame(1, $this->server->streams());
    }

    public function test_a_head_sent_in_pieces_is_waited_for(): void
    {
        $head = self::get($this->tokens->mint(1, ['demo'], 60));
        $browser = $this->browser(substr($head, 0, 10));
        $this->assertSame('', $this->drain($browser));

        fwrite($browser, substr($head, 10));

        $this->assertStringStartsWith('HTTP/1.1 200 OK', $this->drain($browser));
    }

    public function test_an_expired_token_gets_204_and_is_closed(): void
    {
        $token = $this->tokens->mint(1, ['demo'], 60);
        $this->clock->advance('+61 seconds');

        $browser = $this->browser(self::get($token));

        $this->assertSame("HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n", $this->drain($browser));
        $this->assertTrue($this->closedByServer($browser));
        $this->assertSame(0, $this->server->streams());
    }

    public function test_a_forged_token_gets_403(): void
    {
        $browser = $this->browser(self::get('forged'));

        $this->assertStringStartsWith('HTTP/1.1 403 Forbidden', $this->drain($browser));
        $this->assertTrue($this->closedByServer($browser));
    }

    public function test_no_token_gets_403(): void
    {
        $browser = $this->browser("GET /stream HTTP/1.1\r\n\r\n");

        $this->assertStringStartsWith('HTTP/1.1 403 Forbidden', $this->drain($browser));
    }

    public function test_another_path_gets_404(): void
    {
        $browser = $this->browser(self::get($this->tokens->mint(1, ['demo'], 60), '/other'));

        $this->assertStringStartsWith('HTTP/1.1 404 Not Found', $this->drain($browser));
    }

    public function test_a_request_line_that_is_not_http_gets_400(): void
    {
        $browser = $this->browser("hello\r\n\r\n");

        $this->assertStringStartsWith('HTTP/1.1 400 Bad Request', $this->drain($browser));
    }

    public function test_a_head_too_large_is_closed(): void
    {
        $browser = $this->browser('GET /stream?token=' . str_repeat('a', 9000));

        $this->assertTrue($this->closedByServer($browser));
    }

    public function test_a_head_too_slow_is_closed(): void
    {
        $browser = $this->browser('GET /stream');
        $this->assertFalse($this->closedByServer($browser));

        $this->clock->advance('+6 seconds');

        $this->assertTrue($this->closedByServer($browser));
    }

    public function test_an_event_reaches_only_the_streams_granted_its_topic(): void
    {
        $demo = $this->listener(['demo']);
        $users = $this->listener(['module.users', 'demo']);
        $other = $this->listener(['module.files']);

        $this->publish(new Envelope('module.users', 'changed', ['id' => 7], 1));

        $this->assertSame('', $this->drain($demo));
        $this->assertSame("event: module.users\ndata: {\"event\":\"changed\",\"data\":{\"id\":7}}\n\n", $this->drain($users));
        $this->assertSame('', $this->drain($other));
    }

    public function test_events_arrive_in_order(): void
    {
        $browser = $this->listener(['a', 'b']);

        $this->subscriber->push(new Envelope('a', 'one', [], 1));
        $this->subscriber->push(new Envelope('b', 'two', [], 2));
        $this->subscriber->push(new Envelope('a', 'three', [], 3));

        $got = $this->drain($browser);
        $this->assertSame(3, preg_match_all('/"event":"(one|two|three)"/', $got, $m));
        $this->assertSame(['one', 'two', 'three'], $m[1]);
    }

    public function test_a_browser_that_leaves_is_reaped(): void
    {
        $browser = $this->listener();
        $this->assertSame(1, $this->server->streams());

        fclose($browser);
        $this->rounds();

        $this->assertSame(0, $this->server->streams());
    }

    public function test_a_browser_that_leaves_before_its_head_is_reaped(): void
    {
        $browser = $this->browser('GET /str');
        fclose($browser);
        $this->rounds();

        $this->assertSame(0, $this->server->connections());
    }

    public function test_tokens_never_reach_the_log(): void
    {
        $token = $this->tokens->mint(1, ['demo'], 60);
        $this->drain($this->browser(self::get($token)));
        $this->drain($this->browser(self::get('forged-token-value')));

        $this->assertSame(['Opened a stream for user 1 on demo.', 'Refused a stream with 403.'], $this->logger->messages());

        foreach ($this->logger->records() as $record) {
            $this->assertStringNotContainsString($token, $record['message'] . json_encode($record['context']));
            $this->assertStringNotContainsString('forged-token-value', $record['message'] . json_encode($record['context']));
        }
    }
}
