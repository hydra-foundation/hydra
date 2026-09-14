<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Criteria;
use Hydra\Admin\Events\Exported;
use Hydra\Admin\Events\RowCreated;
use Hydra\Admin\Events\RowDeleted;
use Hydra\Admin\Events\RowUpdated;
use Hydra\Admin\LogAdminEventsListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * The listener driven with hand-built events and a fake logger, asserting the
 * audit-trail contract: the level, the key and the context — and that a written
 * row's values are not in the line, however much the event knows them.
 */
#[CoversClass(LogAdminEventsListener::class)]
final class LogAdminEventsListenerTest extends TestCase
{
    private RecordingAdminLogger $logger;
    private LogAdminEventsListener $listener;

    protected function setUp(): void
    {
        $this->logger = new RecordingAdminLogger;
        $this->listener = new LogAdminEventsListener($this->logger);
    }

    public function test_a_write_logs_at_info_with_the_row_it_wrote(): void
    {
        ($this->listener)(new RowCreated('users', '6', ['username' => 'linus']));

        $this->assertSame(
            ['info', 'admin.row_created', ['module' => 'users', 'id' => '6', 'fields' => ['username']]],
            $this->logger->records[0],
        );
    }

    public function test_an_edit_logs_what_moved_rather_than_what_it_moved_to(): void
    {
        ($this->listener)(new RowUpdated('users', '2', ['username' => 'hopper'], ['username' => 'grace']));

        $this->assertSame(
            ['info', 'admin.row_updated', ['module' => 'users', 'id' => '2', 'changed' => ['username']]],
            $this->logger->records[0],
        );
    }

    public function test_a_delete_logs_at_info_with_the_row_that_went(): void
    {
        ($this->listener)(new RowDeleted('users', '2'));

        $this->assertSame(
            ['info', 'admin.row_deleted', ['module' => 'users', 'id' => '2']],
            $this->logger->records[0],
        );
    }

    /**
     * Above the rest, because an export is the one admin action that moves a
     * whole table at once and is worth finding in a log without already
     * knowing to look for it.
     */
    public function test_an_export_logs_above_the_writes(): void
    {
        ($this->listener)(new Exported('users', new Criteria(search: 'smith'), 4812));

        $this->assertSame(
            ['notice', 'admin.exported', ['module' => 'users', 'rows' => 4812, 'view' => ['q' => 'smith']]],
            $this->logger->records[0],
        );
    }

    /**
     * A module's fields are whatever the application declared, and one of them
     * is a password often enough that a listener logging values wholesale would
     * copy a reset into the log the first time somebody used the edit screen.
     */
    public function test_a_written_value_never_reaches_the_log(): void
    {
        ($this->listener)(new RowCreated('users', '6', ['username' => 'linus', 'password' => 'hunter2']));
        ($this->listener)(new RowUpdated('users', '6', ['password' => 'hunter2'], ['password' => 'older']));

        foreach ($this->logger->records as $record) {
            $this->assertStringNotContainsString('hunter2', json_encode($record, JSON_THROW_ON_ERROR));
        }
    }
}

/** Captures every log call as [level, message, context] without writing anywhere. */
final class RecordingAdminLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
