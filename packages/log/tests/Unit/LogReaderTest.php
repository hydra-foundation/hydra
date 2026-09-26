<?php

declare(strict_types=1);

namespace Hydra\Log\Tests\Unit;

use Hydra\Core\Testing\FrozenClock;
use Hydra\Log\LogReader;
use Hydra\Log\LogRecord;
use Hydra\Log\StreamLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reading back what StreamLogger wrote: the file is the only record of its
 * format, so every case here is written by a real StreamLogger first.
 */
#[CoversClass(LogReader::class)]
#[CoversClass(LogRecord::class)]
final class LogReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/hydra-log-reader-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_the_latest_records_come_back_newest_first(): void
    {
        $this->write(static function (StreamLogger $log): void {
            $log->info('first');
            $log->warning('second');
        });

        $records = $this->reader()->latest(1024);

        $this->assertSame(['second', 'first'], array_map(static fn (LogRecord $r): string => $r->message, $records));
        $this->assertSame('warning', $records[0]->level);
        $this->assertSame('2026-09-26T10:00:00+00:00', $records[0]->time->format(DATE_ATOM));
        $this->assertSame(0, $records[1]->offset);
        $this->assertSame(strlen("[2026-09-26T10:00:00+00:00] INFO: first\n"), $records[0]->offset);
        $this->assertNull($records[0]->detail);
        $this->assertSame([], $records[0]->context);
        $this->assertNull($records[0]->requestId);
    }

    public function test_trailing_json_is_the_context_and_carries_the_request_id(): void
    {
        $this->write(static fn (StreamLogger $log) => $log->error('Order {id} failed', ['id' => 7, 'request_id' => 'abc123']));

        [$record] = $this->reader()->latest(1024);

        $this->assertSame('Order 7 failed', $record->message);
        $this->assertSame(['id' => 7, 'request_id' => 'abc123'], $record->context);
        $this->assertSame('abc123', $record->requestId);
    }

    public function test_braces_in_a_message_stay_in_the_message(): void
    {
        $this->write(static function (StreamLogger $log): void {
            $log->notice('template {unknown} and [a] {"not": context');
            $log->notice('ends {"like":"json"} but is a list [1]', ['n' => [1, 2]]);
        });

        [$second, $first] = $this->reader()->latest(1024);

        $this->assertSame('template {unknown} and [a] {"not": context', $first->message);
        $this->assertSame([], $first->context);
        $this->assertSame('ends {"like":"json"} but is a list [1]', $second->message);
        $this->assertSame(['n' => [1, 2]], $second->context);
    }

    public function test_a_json_array_on_the_end_is_not_context(): void
    {
        file_put_contents($this->path, "[2026-09-26T10:00:00+00:00] INFO: ids [1,2] {\"a\":1}x\n[2026-09-26T10:00:00+00:00] INFO: list {1}\n");

        [$list, $junk] = $this->reader()->latest(1024);

        $this->assertSame('list {1}', $list->message);
        $this->assertSame('ids [1,2] {"a":1}x', $junk->message);
        $this->assertSame([], $junk->context);
    }

    public function test_an_exception_is_one_record_with_its_trace_as_the_detail(): void
    {
        $e = new RuntimeException('disk full');
        $this->write(static function (StreamLogger $log) use ($e): void {
            $log->error('Could not record activity: {why}', ['why' => 'boom', 'exception' => $e, 'request_id' => 'r9']);
            $log->info('after');
        });

        [$after, $failure] = $this->reader()->latest(65536);

        $this->assertSame('after', $after->message);
        $this->assertSame('Could not record activity: boom', $failure->message);
        $this->assertStringStartsWith('RuntimeException: disk full in ' . __FILE__ . ':', (string) $failure->detail);
        $this->assertStringContainsString("\n#0 ", (string) $failure->detail);
        $this->assertStringEndsWith('{main}', (string) $failure->detail);
        $this->assertSame(['why' => 'boom', 'request_id' => 'r9'], $failure->context);
        $this->assertSame('r9', $failure->requestId);
    }

    public function test_an_exception_without_other_context_has_none(): void
    {
        $this->write(static fn (StreamLogger $log) => $log->critical('down', ['exception' => new RuntimeException('x')]));

        [$record] = $this->reader()->latest(65536);

        $this->assertSame('down', $record->message);
        $this->assertSame([], $record->context);
        $this->assertStringStartsWith('RuntimeException: x in ', (string) $record->detail);
    }

    public function test_a_line_that_merely_looks_like_a_record_inside_a_trace_stays_in_it(): void
    {
        file_put_contents($this->path, implode("\n", [
            '[2026-09-26T10:00:00+00:00] ERROR: failed',
            '[2026-09-26] INFO: not a stamp',
            '[2026-09-26T10:00:00+00:00] LOUD: not a level',
            '[2026-09-26T10:00:01+00:00] INFO: real',
        ]) . "\n");

        [$real, $failed] = $this->reader()->latest(1024);

        $this->assertSame('real', $real->message);
        $this->assertSame('failed', $failed->message);
        $this->assertSame("[2026-09-26] INFO: not a stamp\n[2026-09-26T10:00:00+00:00] LOUD: not a level", $failed->detail);
    }

    public function test_a_window_that_cuts_a_record_drops_the_part_it_has(): void
    {
        $this->write(static function (StreamLogger $log): void {
            $log->info(str_repeat('a', 100));
            $log->info('kept');
        });
        $kept = strlen('[2026-09-26T10:00:00+00:00] INFO: kept' . "\n");

        $records = $this->reader()->latest($kept + 10);

        $this->assertSame(['kept'], array_map(static fn (LogRecord $r): string => $r->message, $records));
    }

    public function test_a_window_that_starts_on_a_record_keeps_it(): void
    {
        $this->write(static function (StreamLogger $log): void {
            $log->info('dropped');
            $log->info('kept');
        });

        $records = $this->reader()->latest(strlen('[2026-09-26T10:00:00+00:00] INFO: kept' . "\n"));

        $this->assertSame(['kept'], array_map(static fn (LogRecord $r): string => $r->message, $records));
    }

    public function test_a_window_inside_a_line_still_being_written_reads_nothing(): void
    {
        file_put_contents($this->path, '[2026-09-26T10:00:00+00:00] INFO: half a li');

        $this->assertSame([], $this->reader()->latest(5));
    }

    public function test_a_missing_or_empty_file_reads_as_nothing(): void
    {
        $this->assertSame([], $this->reader()->latest(1024));
        $this->assertNull($this->reader()->at(0));

        touch($this->path);

        $this->assertSame([], $this->reader()->latest(1024));
        $this->assertNull($this->reader()->at(0));
    }

    public function test_a_window_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot read the last 0 bytes of a log.');

        $this->reader()->latest(0);
    }

    public function test_a_record_is_found_again_at_its_offset(): void
    {
        $this->write(static function (StreamLogger $log): void {
            $log->info('one');
            $log->error('two', ['exception' => new RuntimeException('x'), 'k' => 'v']);
            $log->info('three');
        });
        $two = $this->reader()->latest(65536)[1];

        $again = $this->reader()->at($two->offset);

        $this->assertEquals($two, $again);
    }

    public function test_an_offset_that_does_not_start_a_record_finds_nothing(): void
    {
        $this->write(static function (StreamLogger $log): void {
            $log->error('two', ['exception' => new RuntimeException('x')]);
        });
        $trace = strpos((string) file_get_contents($this->path), "\n#0 ") + 1;

        $this->assertNull($this->reader()->at(5));
        $this->assertNull($this->reader()->at($trace));
        $this->assertNull($this->reader()->at(-1));
        $this->assertNull($this->reader()->at(100000));
    }

    private function reader(): LogReader
    {
        return new LogReader($this->path);
    }

    /** @param callable(StreamLogger): mixed $writes */
    private function write(callable $writes): void
    {
        $stream = fopen($this->path, 'a');
        $writes(new StreamLogger($stream, new FrozenClock('2026-09-26T10:00:00+00:00')));
        fclose($stream);
    }
}
