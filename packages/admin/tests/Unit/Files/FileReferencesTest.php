<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Files;

use Hydra\Admin\Contracts\FileHolderInterface;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Extractor;
use Hydra\Admin\Files\FileReferences;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileReferences::class)]
#[CoversClass(Reference::class)]
final class FileReferencesTest extends TestCase
{
    private const AVATAR = 'private:avatars/0123456789abcdef0123456789abcdef.png';
    private const MANUAL = 'private:docs/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf';
    private const COVER = 'public:covers/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png';

    private TemporaryDisks $disks;

    protected function setUp(): void
    {
        $this->disks = new TemporaryDisks;
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

        return new FileReferences($registry, $this->disks->disks, $holders);
    }
}
