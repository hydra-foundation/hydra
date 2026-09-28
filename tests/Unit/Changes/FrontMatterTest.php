<?php

declare(strict_types=1);

namespace Hydra\Tests\Unit\Changes;

use Hydra\Tools\Changes\FrontMatter;
use Hydra\Tools\Changes\InvalidChangeFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrontMatter::class)]
#[CoversClass(InvalidChangeFile::class)]
final class FrontMatterTest extends TestCase
{
    public function test_it_splits_flat_fields_from_the_body(): void
    {
        $parsed = FrontMatter::parse("---\nsection: The admin\nkind: added\n---\nA note.\n\nMore.\n", 'a.md');

        $this->assertSame(['section' => 'The admin', 'kind' => 'added'], $parsed->fields);
        $this->assertSame("A note.\n\nMore.", $parsed->body);
        $this->assertSame(['section' => 2, 'kind' => 3], $parsed->lines);
        $this->assertSame(5, $parsed->bodyLine);
    }

    public function test_a_value_keeps_its_colons_and_loses_its_padding(): void
    {
        $parsed = FrontMatter::parse("---\ntitle:   Faces: the return  \n---\n", 'a.md');

        $this->assertSame('Faces: the return', $parsed->fields['title']);
        $this->assertSame('', $parsed->body);
    }

    public function test_windows_line_endings_parse_the_same(): void
    {
        $parsed = FrontMatter::parse("---\r\nkind: fixed\r\n---\r\nA note.\r\n", 'a.md');

        $this->assertSame(['kind' => 'fixed'], $parsed->fields);
        $this->assertSame('A note.', $parsed->body);
    }

    public function test_blank_lines_between_fields_are_allowed(): void
    {
        $parsed = FrontMatter::parse("---\nkind: fixed\n\nsection: Testing\n---\n", 'a.md');

        $this->assertSame(['kind' => 'fixed', 'section' => 'Testing'], $parsed->fields);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function malformed(): iterable
    {
        yield 'no opening fence' => ["kind: added\n", 1, 'must open with ---'];
        yield 'empty file' => ['', 1, 'must open with ---'];
        yield 'unclosed fence' => ["---\nkind: added\nA note.\n", 1, 'never closed'];
        yield 'nested value' => ["---\nsection:\n  name: The admin\n---\n", 2, 'has no value'];
        yield 'indented line' => ["---\nkind: added\n  more: x\n---\n", 3, 'flat'];
        yield 'list item' => ["---\nkind: added\n- x\n---\n", 3, 'expected key: value'];
        yield 'duplicate key' => ["---\nkind: added\nsection: A\nkind: fixed\n---\n", 4, "duplicate key 'kind' (first on line 2)"];
        yield 'bad key' => ["---\nKind: added\n---\n", 2, 'expected key: value'];
    }

    #[DataProvider('malformed')]
    public function test_malformed_front_matter_names_the_file_and_line(string $text, int $line, string $message): void
    {
        try {
            FrontMatter::parse($text, 'changes/unreleased/x.md');
            $this->fail('Expected InvalidChangeFile');
        } catch (InvalidChangeFile $e) {
            $this->assertSame('changes/unreleased/x.md', $e->path);
            $this->assertSame($line, $e->lineNumber);
            $this->assertStringStartsWith("changes/unreleased/x.md:$line: ", $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_render_is_the_inverse_of_parse(): void
    {
        $text = FrontMatter::render(['version' => '0.9.17', 'title' => 'Faces'], "\nIntro.\n\n## The admin\n\n- A note.\n\n");

        $this->assertSame("---\nversion: 0.9.17\ntitle: Faces\n---\nIntro.\n\n## The admin\n\n- A note.\n", $text);

        $parsed = FrontMatter::parse($text, 'a.md');
        $this->assertSame(['version' => '0.9.17', 'title' => 'Faces'], $parsed->fields);
        $this->assertSame("Intro.\n\n## The admin\n\n- A note.", $parsed->body);
    }

    public function test_render_refuses_a_value_that_would_not_parse_back(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'title'");

        FrontMatter::render(['title' => "two\nlines"], '');
    }
}
