<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Files;

use DateTimeImmutable;
use Hydra\Admin\Criteria;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\Files\FileId;
use Hydra\Admin\Files\FileReferences;
use Hydra\Admin\Files\FileSource;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\AvatarUsersModule;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\StoredFilesModule;
use Hydra\Admin\Tests\Support\TemporaryDisks;
use Hydra\Admin\Uploads;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Filesystem\Disks;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileSource::class)]
final class FileSourceTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private const DAY = 86_400;

    private TemporaryDisks $disks;
    private FrozenClock $clock;
    private CrudUserSource $users;
    private FakeContainer $container;
    private FileSource $source;

    protected function setUp(): void
    {
        $this->disks = new TemporaryDisks;
        $this->clock = new FrozenClock(new DateTimeImmutable('@' . time()));
        $this->users = new CrudUserSource;
        $this->container = new FakeContainer([
            AvatarUsersModule::class => new AvatarUsersModule,
            StoredFilesModule::class => new StoredFilesModule,
            CrudUserSource::class => $this->users,
        ]);
        $registry = new ModuleRegistry($this->container, [AvatarUsersModule::class, StoredFilesModule::class]);
        $this->source = new FileSource(
            $this->disks->disks,
            new FileReferences($registry, $this->disks->disks, $this->clock),
            $registry,
        );
        $this->container->instance('files.source', $this->source);
    }

    protected function tearDown(): void
    {
        $this->disks->remove();
    }

    public function test_every_file_on_both_disks_is_a_row_describing_it(): void
    {
        $avatar = $this->avatar('2', 'Grace Portrait.png', 3 * self::DAY);
        $orphan = $this->store(Disks::PRIVATE, 'docs', 2 * self::DAY, "Minutes.\n");
        $new = $this->store(Disks::PUBLIC, 'covers', 60);

        $rows = $this->rows();

        $this->assertSame([$new, $orphan, $avatar], array_column($rows, 'key'), 'Newest first by default.');
        $this->assertSame([
            'id' => FileId::of($avatar),
            'key' => $avatar,
            'preview' => $avatar,
            'download' => $avatar,
            'disk' => 'private',
            'name' => 'Grace Portrait.png',
            'type' => 'image/png',
            'kind' => 'image',
            'size' => strlen(base64_decode(self::PNG)),
            'modified_at' => (string) ($this->clock->now()->getTimestamp() - 3 * self::DAY),
            'status' => 'in_use',
            'uses' => 1,
            'used_by' => [['label' => 'Users #2 · avatar', 'url' => '/admin/users/2']],
        ], $rows[2]);
        $this->assertSame(['orphan', 'text', 'private', basename($orphan), null], [$rows[1]['status'], $rows[1]['kind'], $rows[1]['disk'], $rows[1]['name'], $rows[1]['preview']]);
        $this->assertSame(['new', 'public'], [$rows[0]['status'], $rows[0]['disk']]);
    }

    public function test_the_files_module_showing_a_file_does_not_count_as_using_it(): void
    {
        // Its own image field names every key, which would make every file
        // in use, and walking it would call this source again.
        $this->store(Disks::PRIVATE, 'docs', 2 * self::DAY);

        $this->assertSame(['orphan'], array_column($this->rows(), 'status'));
    }

    public function test_rows_filter_by_disk_kind_and_status(): void
    {
        $avatar = $this->avatar('2', null, 3 * self::DAY);
        $text = $this->store(Disks::PRIVATE, 'docs', 2 * self::DAY, "Minutes.\n");
        $cover = $this->store(Disks::PUBLIC, 'covers', 2 * self::DAY);

        $this->assertSame([$cover], array_column($this->rows(filters: ['disk' => 'public']), 'key'));
        $this->assertSame([$text], array_column($this->rows(filters: ['kind' => 'text']), 'key'));
        $this->assertSame([$avatar], array_column($this->rows(filters: ['status' => 'in_use']), 'key'));
        $this->assertEqualsCanonicalizing([$text, $cover], array_column($this->rows(filters: ['status' => 'orphan']), 'key'));
    }

    public function test_search_matches_the_name_and_the_key(): void
    {
        $avatar = $this->avatar('2', 'Grace Portrait.png', 3 * self::DAY);
        $doc = $this->store(Disks::PRIVATE, 'docs', 2 * self::DAY);

        $this->assertSame([$avatar], array_column($this->rows(search: 'portrait'), 'key'));
        $this->assertSame([$doc], array_column($this->rows(search: 'docs/'), 'key'));
    }

    public function test_rows_sort_by_name_size_and_modified(): void
    {
        $small = $this->store(Disks::PRIVATE, 'docs', 3 * self::DAY, 'a');
        $large = $this->store(Disks::PRIVATE, 'docs', 2 * self::DAY, str_repeat('b', 500));

        $this->assertSame([$small, $large], array_column($this->rows(sort: 'size', direction: 'asc'), 'key'));
        $this->assertSame([$large, $small], array_column($this->rows(sort: 'size', direction: 'desc'), 'key'));
        $this->assertSame([$small, $large], array_column($this->rows(sort: 'modified_at', direction: 'asc'), 'key'));

        $byName = array_column($this->rows(sort: 'name', direction: 'asc'), 'name');
        $sorted = $byName;
        // Natural order, the way a person reads "file 9" before "file 10".
        usort($sorted, strnatcasecmp(...));
        $this->assertSame($sorted, $byName);
    }

    public function test_rows_page(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->store(Disks::PRIVATE, 'docs', (3 - $i) * self::DAY);
        }

        $page = $this->source->page(new Criteria(page: 2, perPage: 2, sort: 'modified_at', direction: 'desc'));

        $this->assertCount(1, $page->rows);
        $this->assertSame(3, $page->total);
    }

    public function test_the_note_totals_the_whole_storage_by_disk(): void
    {
        $this->store(Disks::PRIVATE, 'docs', self::DAY, str_repeat('a', 2048));
        $this->store(Disks::PUBLIC, 'covers', self::DAY, str_repeat('b', 1024));

        $page = $this->source->page(new Criteria(filters: ['disk' => 'public']));

        $this->assertSame('2 files · 3.0KB (private 2.0KB, public 1.0KB)', $page->note);
    }

    public function test_an_empty_storage_lists_nothing(): void
    {
        $page = $this->source->page(new Criteria);

        $this->assertSame([], $page->rows);
        $this->assertSame('0 files · 0B (private 0B, public 0B)', $page->note);
    }

    public function test_find_answers_one_file_with_what_uses_it(): void
    {
        $avatar = $this->avatar('2', 'Grace.png', 3 * self::DAY);

        $row = $this->source->find(FileId::of($avatar));

        $this->assertNotNull($row);
        $this->assertSame('Grace.png', $row['name']);
        $this->assertSame([['label' => 'Users #2 · avatar', 'url' => '/admin/users/2']], $row['used_by']);
    }

    public function test_find_is_null_for_an_id_naming_no_file(): void
    {
        $this->assertNull($this->source->find(FileId::of('private:docs/' . str_repeat('0', 32) . '.png')));
        $this->assertNull($this->source->find('not-an-id'));
        $this->assertNull($this->source->find(rtrim(strtr(base64_encode('private:../etc/passwd'), '+/', '-_'), '=')));
    }

    public function test_an_orphan_can_be_deleted(): void
    {
        $orphan = $this->store(Disks::PRIVATE, 'docs', 2 * self::DAY);

        $this->source->delete(FileId::of($orphan));

        $this->assertFalse($this->exists($orphan));
    }

    public function test_a_file_in_use_is_not_deleted_and_the_refusal_says_by_what(): void
    {
        $avatar = $this->avatar('2', null, 3 * self::DAY);

        try {
            $this->source->delete(FileId::of($avatar));
            $this->fail('A file in use was deleted.');
        } catch (WriteRejected $rejected) {
            $this->assertSame('Users #2 · avatar still uses this file. Remove it there first.', $rejected->summary());
        }

        $this->assertTrue($this->exists($avatar));
    }

    public function test_a_file_that_came_into_use_after_the_list_was_read_is_still_refused(): void
    {
        $file = $this->store(Disks::PRIVATE, 'avatars', 2 * self::DAY);
        $this->assertSame(['orphan'], array_column($this->rows(), 'status'));

        $this->users->update('3', ['avatar' => $file]);

        $this->expectException(WriteRejected::class);

        $this->source->delete(FileId::of($file));
    }

    public function test_an_id_naming_no_file_is_refused(): void
    {
        $this->expectException(WriteRejected::class);

        $this->source->delete('not-an-id');
    }

    public function test_the_delete_screen_answers_a_refusal_with_422_and_the_reason(): void
    {
        $avatar = $this->avatar('2', null, 3 * self::DAY);
        $admin = new AdminHarness(
            [
                AvatarUsersModule::class => new AvatarUsersModule,
                StoredFilesModule::class => new StoredFilesModule,
                CrudUserSource::class => $this->users,
                'files.source' => $this->source,
            ],
            [AvatarUsersModule::class, StoredFilesModule::class],
            uploads: new Uploads($this->disks->disks),
        );

        $response = $admin->controller->destroy(
            $admin->request('POST', '/admin/files/' . FileId::of($avatar) . '/delete'),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Users #2 · avatar still uses this file.', (string) $response->getBody());
        $this->assertTrue($this->exists($avatar));
    }

    public function test_it_describes_exactly_the_columns_its_rows_have(): void
    {
        $this->store(Disks::PRIVATE, 'docs', self::DAY);

        $this->assertSame(array_keys($this->rows()[0]), $this->source->describe()->columns);
    }

    /**
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    private function rows(array $filters = [], ?string $search = null, ?string $sort = 'modified_at', string $direction = 'desc'): array
    {
        return $this->source->page(new Criteria(
            perPage: 100,
            sort: $sort,
            direction: $direction,
            filters: $filters,
            search: $search,
        ))->rows;
    }

    private function avatar(string $user, ?string $name, int $age): string
    {
        $key = $this->store(Disks::PRIVATE, 'avatars', $age);
        $this->users->update($user, ['avatar' => $key, 'avatar_name' => $name]);

        return $key;
    }

    /** Bytes on a disk, $age seconds old. Returns the qualified key. */
    private function store(string $disk, string $directory, int $age, ?string $bytes = null): string
    {
        $key = $this->disks->disks->get($disk)->put(
            $directory,
            (new Psr17Factory)->createStream($bytes ?? base64_decode(self::PNG)),
        );
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
