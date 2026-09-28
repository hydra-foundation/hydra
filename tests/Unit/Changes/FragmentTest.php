<?php

declare(strict_types=1);

namespace Hydra\Tests\Unit\Changes;

use Hydra\Tools\Changes\Fragment;
use Hydra\Tools\Changes\FrontMatter;
use Hydra\Tools\Changes\InvalidChangeFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Fragment::class)]
#[CoversClass(FrontMatter::class)]
#[CoversClass(InvalidChangeFile::class)]
final class FragmentTest extends TestCase
{
    private const FILE = 'changes/unreleased/file-uploads.md';

    public function test_a_fragment_carries_its_slug_section_kind_and_note(): void
    {
        $fragment = Fragment::parse("---\nsection: The admin\nkind: added\n---\n`Input::file()` declares a file control.\n", self::FILE);

        $this->assertSame('file-uploads', $fragment->slug);
        $this->assertSame('The admin', $fragment->section);
        $this->assertSame('added', $fragment->kind);
        $this->assertSame('`Input::file()` declares a file control.', $fragment->note);
        $this->assertNull($fragment->upgrading);
    }

    public function test_upgrading_is_split_off_the_note(): void
    {
        $fragment = Fragment::parse(<<<'MD'
            ---
            section: The admin
            kind: added
            ---
            `Input::file()` declares a file control.

            ### Upgrading

            Register `FilesystemServiceProvider` first.

            MD, self::FILE);

        $this->assertSame('`Input::file()` declares a file control.', $fragment->note);
        $this->assertSame('Register `FilesystemServiceProvider` first.', $fragment->upgrading);
    }

    public function test_every_kind_is_accepted(): void
    {
        foreach (Fragment::KINDS as $kind) {
            $this->assertSame($kind, Fragment::parse("---\nsection: A\nkind: $kind\n---\nA note.\n", self::FILE)->kind);
        }
    }

    /**
     * @return iterable<string, array{string, int|null, string}>
     */
    public static function invalid(): iterable
    {
        yield 'missing section' => ["---\nkind: added\n---\nA note.\n", 1, "missing 'section'"];
        yield 'missing kind' => ["---\nsection: A\n---\nA note.\n", 1, "missing 'kind'"];
        yield 'unknown kind' => ["---\nsection: A\nkind: new\n---\nA note.\n", 3, "'new' is not a kind; use one of security, removed, changed, added, fixed"];
        yield 'unknown key' => ["---\nsection: A\nkind: added\nknid: fixed\n---\nA note.\n", 4, "unknown key 'knid'"];
        yield 'empty note' => ["---\nsection: A\nkind: added\n---\n\n", 5, 'no note'];
        yield 'note only upgrading' => ["---\nsection: A\nkind: added\n---\n### Upgrading\n\nDo a thing.\n", 5, 'no note'];
        yield 'empty upgrading' => ["---\nsection: A\nkind: added\n---\nA note.\n\n### Upgrading\n", 7, 'Upgrading is empty'];
        yield 'two upgradings' => ["---\nsection: A\nkind: added\n---\nA note.\n\n### Upgrading\n\nOne.\n\n### Upgrading\n\nTwo.\n", 11, 'second ### Upgrading'];
    }

    #[DataProvider('invalid')]
    public function test_an_invalid_fragment_names_the_file_and_line(string $text, ?int $line, string $message): void
    {
        try {
            Fragment::parse($text, self::FILE);
            $this->fail('Expected InvalidChangeFile');
        } catch (InvalidChangeFile $e) {
            $this->assertSame(self::FILE, $e->path);
            $this->assertSame($line, $e->lineNumber);
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_the_untouched_template_is_refused_at_each_placeholder(): void
    {
        $template = Fragment::template();

        foreach ([
            [$template, 2, 'section is still the placeholder'],
            [str_replace(Fragment::PLACEHOLDER_SECTION, 'The admin', $template), 5, 'the note is still the placeholder'],
            [str_replace([Fragment::PLACEHOLDER_SECTION, Fragment::PLACEHOLDER_NOTE], ['The admin', 'A note.'], $template), 7, '### Upgrading is still the placeholder'],
        ] as [$text, $line, $message]) {
            try {
                Fragment::parse($text, self::FILE);
                $this->fail("Expected: $message");
            } catch (InvalidChangeFile $e) {
                $this->assertSame($line, $e->lineNumber);
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_a_filled_in_template_parses(): void
    {
        $text = str_replace(
            [Fragment::PLACEHOLDER_NOTE, "\n\n### Upgrading\n\n" . Fragment::PLACEHOLDER_UPGRADING],
            ['A note.', ''],
            Fragment::template('Validation', 'fixed'),
        );

        $fragment = Fragment::parse($text, self::FILE);

        $this->assertSame(['Validation', 'fixed', 'A note.', null], [$fragment->section, $fragment->kind, $fragment->note, $fragment->upgrading]);
    }

    public function test_a_slug_that_is_not_kebab_case_is_refused(): void
    {
        $this->expectException(InvalidChangeFile::class);
        $this->expectExceptionMessage('kebab-case');

        Fragment::parse("---\nsection: A\nkind: added\n---\nA note.\n", 'changes/unreleased/File Uploads.md');
    }

    public function test_it_reads_from_disk(): void
    {
        $path = sys_get_temp_dir() . '/hydra-fragment-' . bin2hex(random_bytes(4)) . '.md';
        file_put_contents($path, "---\nsection: Testing\nkind: fixed\n---\nA note.\n");

        try {
            $this->assertSame('Testing', Fragment::fromFile($path)->section);
        } finally {
            unlink($path);
        }
    }

    public function test_an_unreadable_file_is_named(): void
    {
        $this->expectException(InvalidChangeFile::class);
        $this->expectExceptionMessage('/nowhere/at-all.md: cannot be read');

        Fragment::fromFile('/nowhere/at-all.md');
    }
}
