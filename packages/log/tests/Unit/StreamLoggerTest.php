<?php

declare(strict_types=1);

namespace Hydra\Log\Tests\Unit;

use Hydra\Log\StreamLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The PSR-3 logger's line format and context interpolation, and the cases where
 * logging must not become the failure: a non-stringable value, a closed stream.
 */
final class StreamLoggerTest extends TestCase
{
    /** @var resource */
    private $stream;
    private StreamLogger $logger;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'r+');
        $this->logger = new StreamLogger($this->stream);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private function contents(): string
    {
        rewind($this->stream);
        return stream_get_contents($this->stream);
    }

    public function test_is_psr3_logger(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, $this->logger);
    }

    public function test_writes_level_and_message_on_one_line(): void
    {
        $this->logger->error('boom');

        $out = $this->contents();
        $this->assertStringContainsString('ERROR', $out);
        $this->assertStringContainsString('boom', $out);
        $this->assertStringEndsWith("\n", $out);
        $this->assertSame(1, substr_count($out, "\n"), 'one record => one line');
    }

    public function test_interpolates_placeholders(): void
    {
        $this->logger->info('Hello {name}', ['name' => 'Will']);

        $this->assertStringContainsString('Hello Will', $this->contents());
    }

    public function test_renders_falsy_context_values_instead_of_dropping_them(): void
    {
        // The whole point: a naive truthiness check would erase 0 / '' / false.
        $this->logger->info('count={count} empty={empty} flag={flag}', [
            'count' => 0,
            'empty' => '',
            'flag' => false,
        ]);

        $out = $this->contents();
        $this->assertStringContainsString('count=0', $out);
        $this->assertStringContainsString('empty= ', $out); // '' rendered, next token follows
        $this->assertStringNotContainsString('{count}', $out);
        $this->assertStringNotContainsString('{flag}', $out);
    }

    public function test_leaves_unmatched_placeholders_intact(): void
    {
        $this->logger->info('hi {missing}');

        $this->assertStringContainsString('{missing}', $this->contents());
    }

    public function test_does_not_fatal_on_non_stringable_context_value(): void
    {
        // Arrays/resources can't be cast to string: the placeholder is left
        // intact rather than throwing, and the value still appears in context.
        $this->logger->info('data {payload}', ['payload' => ['a' => 1]]);

        $out = $this->contents();
        $this->assertStringContainsString('{payload}', $out);
        $this->assertStringContainsString('payload', $out);
    }

    public function test_appends_remaining_context_as_json(): void
    {
        $this->logger->info('saved', ['user_id' => 7]);

        $out = $this->contents();
        $this->assertStringContainsString('user_id', $out);
        $this->assertStringContainsString('7', $out);
    }

    public function test_renders_exception_context_specially(): void
    {
        $e = new RuntimeException('kaboom');
        $this->logger->error('request failed', ['exception' => $e]);

        $out = $this->contents();
        $this->assertStringContainsString('request failed', $out);
        $this->assertStringContainsString(RuntimeException::class, $out);
        $this->assertStringContainsString('kaboom', $out);
        $this->assertStringContainsString((string) $e->getLine(), $out);
    }

    public function test_accepts_all_psr3_levels(): void
    {
        foreach ([
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR,
            LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG,
        ] as $level) {
            $this->logger->log($level, "msg-$level");
        }

        $out = $this->contents();
        $this->assertSame(8, substr_count($out, "\n"));
        $this->assertStringContainsString('EMERGENCY', $out);
        $this->assertStringContainsString('DEBUG', $out);
    }

    public function test_never_throws_when_stream_is_closed(): void
    {
        fclose($this->stream);

        // A logging sink failure must not become an application failure.
        $this->logger->error('still safe');
        $this->expectNotToPerformAssertions();
    }
}
