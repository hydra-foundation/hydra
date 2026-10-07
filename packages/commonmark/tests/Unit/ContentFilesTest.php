<?php

declare(strict_types=1);

namespace Hydra\CommonMark\Tests\Unit;

use Hydra\CommonMark\CommonMarkRenderer;
use Hydra\View\Content\ContentDirectory;
use Hydra\View\InvalidFrontMatter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A ContentDirectory over the real renderer: a file read for a listing and
 * then rendered must come out exactly as a full parse() of it would, or a
 * post would read differently in the admin and on the site.
 */
#[CoversNothing]
final class ContentFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-content-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function sources(): iterable
    {
        yield 'no front matter' => ["# Title\n\nBody."];
        yield 'a post' => ["---\ntitle: First post\ndate: 2026-10-10\ntags: [code, hydra]\n---\n\n## Heading\n\nBody with `code`.\n"];
        yield 'only a comment' => ["---\n# title: not yet\n---\nBody."];
        yield 'windows line endings' => ["---\r\ntitle: x\r\n---\r\n\r\nBody.\r\n"];
        yield 'nothing below it' => ["---\ntitle: Empty\n---\n"];
        yield 'blank lines then a list' => ["---\ntitle: x\n---\n\n\n\n- one\n- two\n"];
        yield 'a rule further down' => ["Intro\n\n---\ntitle: no\n---\nmore"];
        yield 'an unclosed block' => ["---\ntitle: x\nBody."];
        yield 'closing dashes at the very end' => ["---\ntitle: x\n---"];
        yield 'raw html' => ["---\ntitle: x\n---\n<details><summary>s</summary>\n\nx\n\n</details>\n"];
    }

    #[DataProvider('sources')]
    public function test_a_listed_file_renders_as_a_full_parse_would(string $source): void
    {
        file_put_contents($this->dir . '/post.md', $source);
        $markdown = new CommonMarkRenderer;
        $directory = new ContentDirectory($this->dir, $markdown);

        $file = $directory->find('post');
        $this->assertNotNull($file);

        foreach ([false, true] as $trusted) {
            $expected = $markdown->parse($source, $trusted);
            $document = $directory->document($file, $trusted);

            $this->assertEquals($expected->meta, $document->meta);
            $this->assertSame((string) $expected->html, (string) $document->html);
        }
    }

    public function test_a_broken_post_is_listed_with_the_parser_s_reason(): void
    {
        file_put_contents($this->dir . '/good.md', "---\ntitle: Good\n---\nx");
        file_put_contents($this->dir . '/bad.md', "---\ntitle: [unclosed\n---\nx");

        [$bad, $good] = (new ContentDirectory($this->dir, new CommonMarkRenderer))->all();

        $this->assertInstanceOf(InvalidFrontMatter::class, $bad->error);
        $this->assertStringStartsWith('The front matter', $bad->error->getMessage());
        $this->assertSame(['title' => 'Good'], $good->meta);
    }
}
