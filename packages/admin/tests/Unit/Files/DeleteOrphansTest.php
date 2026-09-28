<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Files;

use DateTimeImmutable;
use Hydra\Admin\Contracts\FileHolderInterface;
use Hydra\Admin\Files\DeleteOrphans;
use Hydra\Admin\Files\FileReferences;
use Hydra\Admin\Files\Reference;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Tests\Support\ChangingHolder;
use Hydra\Admin\Tests\Support\HoldingSource;
use Hydra\Admin\Tests\Support\TemporaryDisks;
use Hydra\Admin\Tests\Support\UndeletableStorage;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Filesystem\Disks;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

#[CoversClass(DeleteOrphans::class)]
final class DeleteOrphansTest extends TestCase
{
    private const DAY = 86_400;

    private TemporaryDisks $disks;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->disks = new TemporaryDisks;
        $this->clock = new FrozenClock(new DateTimeImmutable('@' . time()));
    }

    protected function tearDown(): void
    {
        $this->disks->remove();
    }

    public function test_orphans_on_both_disks_are_deleted_and_counted(): void
    {
        $private = $this->store(Disks::PRIVATE, 2 * self::DAY, str_repeat('a', 1024));
        $public = $this->store(Disks::PUBLIC, 2 * self::DAY, str_repeat('b', 512));

        $message = $this->action()->run();

        $this->assertSame('Deleted 2 orphaned files (1.5KB).', $message);
        $this->assertFalse($this->exists($private));
        $this->assertFalse($this->exists($public));
    }

    public function test_one_orphan_is_one_file(): void
    {
        $this->store(Disks::PRIVATE, 2 * self::DAY, 'a');

        $this->assertSame('Deleted 1 orphaned file (1B).', $this->action()->run());
    }

    public function test_a_referenced_file_and_a_new_one_are_kept(): void
    {
        $used = $this->store(Disks::PRIVATE, 30 * self::DAY, 'used');
        $new = $this->store(Disks::PRIVATE, 60, 'new');
        $orphan = $this->store(Disks::PRIVATE, 2 * self::DAY, 'orphan');

        $this->action(new HoldingSource([new Reference($used, 'BlogImages')]))->run();

        $this->assertTrue($this->exists($used));
        $this->assertTrue($this->exists($new));
        $this->assertFalse($this->exists($orphan));
    }

    public function test_a_file_that_comes_into_use_between_the_two_reads_is_kept(): void
    {
        $taken = $this->store(Disks::PRIVATE, 2 * self::DAY, 'taken');
        $orphan = $this->store(Disks::PRIVATE, 2 * self::DAY, 'orphan');
        $holder = new ChangingHolder([], [new Reference($taken, 'BlogImages')]);

        $message = $this->action($holder)->run();

        $this->assertTrue($this->exists($taken), 'A file a row took up after the orphans were found was deleted.');
        $this->assertFalse($this->exists($orphan));
        $this->assertSame('Deleted 1 orphaned file (6B).', $message);
        $this->assertSame(2, $holder->reads);
    }

    public function test_with_no_orphans_it_says_so(): void
    {
        $this->store(Disks::PRIVATE, 60, 'new');

        $this->assertSame('No orphaned files to delete.', $this->action()->run());
    }

    public function test_a_file_that_cannot_be_deleted_is_counted_and_logged_and_the_rest_still_go(): void
    {
        $private = $this->store(Disks::PRIVATE, 2 * self::DAY, 'private');
        $public = $this->store(Disks::PUBLIC, 2 * self::DAY, 'public');
        $disks = new Disks(new UndeletableStorage($this->disks->disks->private()), $this->disks->disks->public());
        $log = new WarningsLogger;
        $references = new FileReferences(new ModuleRegistry(new FakeContainer([]), []), $disks, $this->clock);

        $message = (new DeleteOrphans($references, $disks, $log))->run();

        $this->assertSame('Deleted 1 orphaned file (6B). 1 could not be deleted; the log says why.', $message);
        $this->assertTrue($this->exists($private));
        $this->assertFalse($this->exists($public));
        $this->assertSame(['Could not delete an orphaned file.'], $log->warnings);
    }

    private function action(FileHolderInterface ...$holders): DeleteOrphans
    {
        $references = new FileReferences(
            new ModuleRegistry(new FakeContainer([]), []),
            $this->disks->disks,
            $this->clock,
            array_values($holders),
        );

        return new DeleteOrphans($references, $this->disks->disks);
    }

    private function store(string $disk, int $age, string $bytes): string
    {
        $key = $this->disks->disks->get($disk)->put('files', (new Psr17Factory)->createStream($bytes));
        touch($this->disks->root . '/' . $disk . '/' . $key, $this->clock->now()->getTimestamp() - $age);
        clearstatcache();

        return $this->disks->disks->qualify($disk, $key);
    }

    private function exists(string $qualified): bool
    {
        [$disk, $key] = $this->disks->disks->locate($qualified);

        return $disk->exists($key);
    }
}

/** Keeps the warnings, which is all these tests read. */
final class WarningsLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::WARNING) {
            $this->warnings[] = (string) $message;
        }
    }
}
