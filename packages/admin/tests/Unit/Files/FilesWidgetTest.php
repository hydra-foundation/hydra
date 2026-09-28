<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Files;

use DateTimeImmutable;
use Hydra\Admin\Files\FileId;
use Hydra\Admin\Files\FileReferences;
use Hydra\Admin\Files\FileSource;
use Hydra\Admin\Files\FilesWidget;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Tests\Support\AvatarUsersModule;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\StoredFilesModule;
use Hydra\Admin\Tests\Support\TemporaryDisks;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Filesystem\Disks;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesWidget::class)]
#[CoversClass(FileSource::class)]
final class FilesWidgetTest extends TestCase
{
    private const DAY = 86_400;

    private TemporaryDisks $disks;
    private FrozenClock $clock;
    private CrudUserSource $users;
    private FilesWidget $widget;

    protected function setUp(): void
    {
        $this->disks = new TemporaryDisks;
        $this->clock = new FrozenClock(new DateTimeImmutable('@' . time()));
        $this->users = new CrudUserSource;
        $container = new FakeContainer([
            AvatarUsersModule::class => new AvatarUsersModule,
            StoredFilesModule::class => new StoredFilesModule,
            CrudUserSource::class => $this->users,
        ]);
        $registry = new ModuleRegistry($container, [AvatarUsersModule::class, StoredFilesModule::class]);
        $source = new FileSource($this->disks->disks, new FileReferences($registry, $this->disks->disks, $this->clock), $registry);
        $container->instance('files.source', $source);
        $this->widget = new FilesWidget($source, $registry);
    }

    protected function tearDown(): void
    {
        $this->disks->remove();
    }

    public function test_it_counts_and_sizes_each_disk_and_the_orphans(): void
    {
        $avatar = $this->store(Disks::PRIVATE, 5 * self::DAY, str_repeat('a', 2048));
        $this->users->update('2', ['avatar' => $avatar]);
        $this->store(Disks::PRIVATE, 4 * self::DAY, str_repeat('b', 1024));
        $this->store(Disks::PUBLIC, 60, str_repeat('c', 512));

        $view = $this->widget->present();

        $this->assertSame(3, $view['files']);
        $this->assertSame('3.5KB', $view['size']);
        $this->assertSame([
            ['disk' => 'private', 'files' => 2, 'size' => '3.0KB'],
            ['disk' => 'public', 'files' => 1, 'size' => '512B'],
        ], $view['disks']);
        $this->assertSame(1, $view['orphans']);
        $this->assertSame('/admin/files?status=orphan', $view['orphansUrl']);
        $this->assertSame('/admin/files', $view['url']);
    }

    public function test_it_lists_the_five_newest_files_newest_first_with_links(): void
    {
        $keys = [];

        for ($age = 7; $age >= 1; $age--) {
            $keys[] = $this->store(Disks::PRIVATE, $age * self::DAY, "file {$age}");
        }

        $newest = $this->widget->present()['newest'];

        $this->assertCount(5, $newest);
        $this->assertSame(array_map(basename(...), array_reverse(array_slice($keys, 2))), array_column($newest, 'name'));
        $this->assertSame('/admin/files/' . FileId::of($keys[6]), $newest[0]['url']);
        $this->assertSame('6B', $newest[0]['size']);
        $this->assertSame((string) ($this->clock->now()->getTimestamp() - self::DAY), $newest[0]['modified_at']);
    }

    public function test_empty_storage_reads_as_nothing(): void
    {
        $view = $this->widget->present();

        $this->assertSame([0, '0B', 0, []], [$view['files'], $view['size'], $view['orphans'], $view['newest']]);
    }

    private function store(string $disk, int $age, string $bytes): string
    {
        $key = $this->disks->disks->get($disk)->put('files', (new Psr17Factory)->createStream($bytes));
        touch($this->disks->root . '/' . $disk . '/' . $key, $this->clock->now()->getTimestamp() - $age);
        clearstatcache();

        return $this->disks->disks->qualify($disk, $key);
    }
}
