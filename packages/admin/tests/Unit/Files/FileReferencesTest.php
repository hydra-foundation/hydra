<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Files;

use Hydra\Admin\Contracts\FileHolderInterface;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Extractor;
use DateTimeImmutable;
use Hydra\Admin\Files\FileReferences;
use Hydra\Admin\Files\Orphan;
use Hydra\Admin\Files\Reference;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\AvatarUsersModule;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\DocumentsModule;
use Hydra\Admin\Tests\Support\ExplodingSource;
use Hydra\Admin\Tests\Support\HeldModule;
use Hydra\Admin\Tests\Support\HoldingSource;
use Hydra\Admin\Tests\Support\NoFilesModule;
use Hydra\Admin\Tests\Support\TemporaryDisks;
use Hydra\Admin\Tests\Support\UnnumberedModule;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Filesystem\Disks;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FileReferences::class)]
#[CoversClass(Reference::class)]
#[CoversClass(Orphan::class)]
final class FileReferencesTest extends TestCase
{
    private const AVATAR = 'private:avatars/0123456789abcdef0123456789abcdef.png';
    private const MANUAL = 'private:docs/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf';
    private const COVER = 'public:covers/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

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

    public function test_a_form_file_column_is_referenced_with_its_row_and_kept_name(): void
    {
        $users = new CrudUserSource;
        $users->update('2', ['avatar' => self::AVATAR, 'avatar_name' => 'Grace.png']);

        $references = $this->references([AvatarUsersModule::class => new AvatarUsersModule], [CrudUserSource::class => $users]);

        $this->assertEquals([new Reference(self::AVATAR, 'users', '2', 'avatar', 'Grace.png')], $references);
    }

    public function test_read_side_file_and_image_fields_are_referenced_without_a_form(): void
    {
        $references = $this->references([DocumentsModule::class => new DocumentsModule], [
            'documents.source' => new ArraySource([
                ['id' => 7, 'attachment' => self::MANUAL, 'attachment_name' => 'Manual.pdf', 'cover' => self::COVER],
            ]),
        ]);

        $this->assertEqualsCanonicalizing([
            new Reference(self::MANUAL, 'documents', '7', 'attachment', 'Manual.pdf'),
            new Reference(self::COVER, 'documents', '7', 'cover', null),
        ], $references);
    }

    public function test_a_source_that_holds_its_own_files_is_asked_rather_than_walked(): void
    {
        $held = new Reference(self::COVER, 'held', '3', 'picture', null);

        $references = $this->references([HeldModule::class => new HeldModule], [
            'held.source' => new HoldingSource([$held]),
        ]);

        $this->assertEquals([$held], $references);
    }

    public function test_the_apps_own_holders_are_included(): void
    {
        $blog = new Reference(self::MANUAL, 'BlogImages', null, null, 'Diagram.pdf');

        $references = $this->references([], [], [new HoldingSource([$blog])]);

        $this->assertEquals([$blog], $references);
    }

    public function test_rows_past_the_export_cap_are_read(): void
    {
        $rows = [];

        for ($id = 1; $id <= Extractor::MAX_ROWS; $id++) {
            $rows[] = ['id' => $id];
        }

        $rows[] = ['id' => Extractor::MAX_ROWS + 1, 'attachment' => self::MANUAL];

        $references = $this->references([DocumentsModule::class => new DocumentsModule], [
            'documents.source' => new ArraySource($rows),
        ]);

        $this->assertEquals([new Reference(self::MANUAL, 'documents', (string) (Extractor::MAX_ROWS + 1), 'attachment', null)], $references);
    }

    public function test_a_module_holding_no_files_is_never_read(): void
    {
        $this->assertSame([], $this->references([NoFilesModule::class => new NoFilesModule], [
            'plain.source' => new ExplodingSource,
        ]));
    }

    public function test_a_value_that_names_no_file_is_skipped(): void
    {
        $references = $this->references([DocumentsModule::class => new DocumentsModule], [
            'documents.source' => new ArraySource([
                ['id' => 1, 'attachment' => null],
                ['id' => 2, 'attachment' => ''],
                ['id' => 3, 'attachment' => 'private:../etc/passwd'],
                ['id' => 4, 'attachment' => 'elsewhere:docs/x.pdf'],
                ['id' => 5, 'attachment' => 'no-disk-at-all.pdf'],
                ['id' => 6, 'attachment' => 42],
                ['id' => 7, 'attachment' => self::MANUAL],
            ]),
        ]);

        $this->assertEquals([new Reference(self::MANUAL, 'documents', '7', 'attachment', null)], $references);
    }

    public function test_a_holder_naming_no_file_is_skipped_too(): void
    {
        $references = $this->references([], [], [new HoldingSource([
            new Reference('private:../etc/passwd', 'BlogImages', null, null, null),
            new Reference(self::MANUAL, 'BlogImages', null, null, null),
        ])]);

        $this->assertEquals([new Reference(self::MANUAL, 'BlogImages', null, null, null)], $references);
    }

    public function test_a_module_with_no_id_field_names_no_row(): void
    {
        $references = $this->references([UnnumberedModule::class => new UnnumberedModule], [
            'unnumbered.source' => new ArraySource([['doc' => self::MANUAL]]),
        ]);

        $this->assertEquals([new Reference(self::MANUAL, 'unnumbered', null, 'doc', null)], $references);
    }

    public function test_to_answers_every_reference_to_one_key(): void
    {
        $files = $this->files([DocumentsModule::class => new DocumentsModule], [
            'documents.source' => new ArraySource([
                ['id' => 1, 'attachment' => self::MANUAL, 'attachment_name' => 'Manual.pdf'],
                ['id' => 2, 'attachment' => self::MANUAL],
                ['id' => 3, 'cover' => self::COVER],
            ]),
        ]);

        $this->assertEquals([
            new Reference(self::MANUAL, 'documents', '1', 'attachment', 'Manual.pdf'),
            new Reference(self::MANUAL, 'documents', '2', 'attachment', null),
        ], $files->to(self::MANUAL));
        $this->assertSame([], $files->to('private:docs/cccccccccccccccccccccccccccccccc.pdf'));
    }

    public function test_an_unreferenced_file_past_the_grace_period_is_an_orphan(): void
    {
        $key = $this->stored(Disks::PRIVATE, 'docs', self::DAY + 1);

        $orphans = $this->orphans($this->files([], []));

        $this->assertSame([$key], array_map(static fn (Orphan $orphan): string => $orphan->qualified, $orphans));
        $this->assertSame(substr($key, strlen('private:')), $orphans[0]->file->key);
        $this->assertSame('image/png', $orphans[0]->file->mimeType);
    }

    public function test_an_image_copy_is_not_an_orphan(): void
    {
        // hydrakit/image keeps its copies under variants/ on the public disk:
        // nothing references them, and they are not uploads to tidy away.
        mkdir($this->disks->root . '/public/variants/content', 0o777, true);
        file_put_contents($this->disks->root . '/public/variants/content/abc-480.webp', 'copy');
        touch($this->disks->root . '/public/variants/content/abc-480.webp', time() - 10 * self::DAY);
        $upload = $this->stored(Disks::PUBLIC, 'covers', self::DAY + 1);

        $this->assertSame([$upload], $this->qualified($this->orphans($this->files([], []))));
    }

    public function test_a_file_exactly_the_grace_period_old_is_an_orphan_and_one_second_younger_is_not(): void
    {
        $old = $this->stored(Disks::PRIVATE, 'docs', self::DAY);
        $this->stored(Disks::PRIVATE, 'docs', self::DAY - 1);

        $this->assertSame([$old], $this->qualified($this->orphans($this->files([], []))));
    }

    public function test_a_file_stored_moments_ago_is_not_an_orphan_yet(): void
    {
        // A form stores its file before its row is written: every upload is
        // briefly referenced by nothing.
        $this->stored(Disks::PRIVATE, 'avatars', 0);

        $this->assertSame([], $this->orphans($this->files([], [])));
    }

    public function test_a_referenced_file_is_never_an_orphan_however_old(): void
    {
        $key = $this->stored(Disks::PRIVATE, 'docs', 365 * self::DAY);

        $files = $this->files([DocumentsModule::class => new DocumentsModule], [
            'documents.source' => new ArraySource([['id' => 1, 'attachment' => $key]]),
        ]);

        $this->assertSame([], $this->orphans($files));
    }

    public function test_both_disks_are_walked_and_keys_are_compared_with_their_disk(): void
    {
        $private = $this->stored(Disks::PRIVATE, 'covers', 2 * self::DAY);
        $public = $this->stored(Disks::PUBLIC, 'covers', 2 * self::DAY);
        // The private file's key, but on the public disk: not the same file.
        $elsewhere = 'public:' . substr($private, strlen('private:'));

        $files = $this->files([], [], [new HoldingSource([new Reference($elsewhere, 'BlogImages')])]);

        $this->assertEqualsCanonicalizing([$private, $public], $this->qualified($this->orphans($files)));
    }

    public function test_the_grace_period_can_be_set(): void
    {
        $key = $this->stored(Disks::PRIVATE, 'docs', 3601);
        $registry = new ModuleRegistry(new FakeContainer([]), []);

        $files = new FileReferences($registry, $this->disks->disks, $this->clock, graceSeconds: 3600);

        $this->assertSame([$key], $this->qualified($this->orphans($files)));
    }

    public function test_a_source_that_cannot_be_read_stops_the_search_before_any_orphan(): void
    {
        $this->stored(Disks::PRIVATE, 'docs', 2 * self::DAY);
        $files = $this->files([DocumentsModule::class => new DocumentsModule], [
            'documents.source' => new ExplodingSource,
        ]);

        $this->assertNothingYieldedBeforeFailing($files);
    }

    public function test_a_holder_that_fails_stops_the_search_before_any_orphan(): void
    {
        $this->stored(Disks::PRIVATE, 'docs', 2 * self::DAY);
        $failing = new class implements FileHolderInterface {
            public function references(): iterable
            {
                yield new Reference('private:docs/dddddddddddddddddddddddddddddddd.pdf', 'first');

                throw new RuntimeException('The holder failed partway.');
            }
        };

        $this->assertNothingYieldedBeforeFailing($this->files([], [], [$failing]));
    }

    private function assertNothingYieldedBeforeFailing(FileReferences $files): void
    {
        $yielded = [];

        try {
            foreach ($files->orphans() as $orphan) {
                $yielded[] = $orphan;
            }

            $this->fail('An incomplete set of references was used to find orphans.');
        } catch (RuntimeException $failure) {
            $this->assertNotSame('An incomplete set of references was used to find orphans.', $failure->getMessage());
        }

        $this->assertSame([], $yielded);
    }

    /** A PNG on the disk, $age seconds old by its modified time. Returns its qualified key. */
    private function stored(string $disk, string $directory, int $age): string
    {
        $key = $this->disks->disks->get($disk)->put($directory, (new Psr17Factory)->createStream(base64_decode(self::PNG)));
        touch($this->disks->root . '/' . $disk . '/' . $key, $this->clock->now()->getTimestamp() - $age);
        clearstatcache();

        return $this->disks->disks->qualify($disk, $key);
    }

    /** @return list<Orphan> */
    private function orphans(FileReferences $files): array
    {
        return iterator_to_array($files->orphans(), false);
    }

    /**
     * @param list<Orphan> $orphans
     * @return list<string>
     */
    private function qualified(array $orphans): array
    {
        return array_map(static fn (Orphan $orphan): string => $orphan->qualified, $orphans);
    }

    /**
     * @param array<class-string<ModuleInterface>, ModuleInterface> $modules
     * @param array<string, object> $services
     * @param list<FileHolderInterface> $holders
     * @return list<Reference>
     */
    private function references(array $modules, array $services, array $holders = []): array
    {
        return iterator_to_array($this->files($modules, $services, $holders)->all(), false);
    }

    /**
     * @param array<class-string<ModuleInterface>, ModuleInterface> $modules
     * @param array<string, object> $services
     * @param list<FileHolderInterface> $holders
     */
    private function files(array $modules, array $services, array $holders = []): FileReferences
    {
        $registry = new ModuleRegistry(new FakeContainer([...$modules, ...$services]), array_keys($modules));

        return new FileReferences($registry, $this->disks->disks, $this->clock, $holders);
    }
}
