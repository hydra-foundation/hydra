<?php

declare(strict_types=1);

namespace Hydra\View\Tests\Unit\Content;

use DateTimeImmutable;
use Hydra\View\Content\ContentDirectory;
use Hydra\View\Content\ContentFile;
use Hydra\View\Contracts\MarkdownInterface;
use Hydra\View\Document;
use Hydra\View\HtmlView;
use Hydra\View\InvalidFrontMatter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** A directory of Markdown files read as content: listed by slug, rendered on demand. */
#[CoversClass(ContentDirectory::class)]
#[CoversClass(ContentFile::class)]
final class ContentDirectoryTest extends TestCase
{
    private string $dir;

    /** @var array{frontMatter: int, toHtml: int, parse: int} */
    private array $calls = ['frontMatter' => 0, 'toHtml' => 0, 'parse' => 0];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-content-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
                self::remove($path . '/' . $name);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    public function test_every_markdown_file_with_a_linkable_name_is_listed_by_slug(): void
    {
        $this->write('zebra.md', "---\ntitle: Z\n---\nz");
        $this->write('hello-world.md', "---\ntitle: Hello\n---\nh");
        $this->write('hello.md', 'listed by slug, so before hello-world, though not by file name');
        $this->write('2026-10-10.md', 'dated');
        $this->write('notes.txt', 'not markdown');
        $this->write('Draft.md', 'capital');
        $this->write('two words.md', 'space');
        $this->write('.hidden.md', 'dot');
        $this->write('readme.MD', 'upper extension');
        mkdir($this->dir . '/folder.md');

        $slugs = array_map(static fn (ContentFile $f): string => $f->slug, $this->directory()->all());

        $this->assertSame(['2026-10-10', 'hello', 'hello-world', 'zebra'], $slugs);
    }

    public function test_a_file_carries_what_a_listing_needs(): void
    {
        $this->write('hello.md', "---\ntitle: Hello\n---\n\n# Body\n\nText.\n");
        touch($this->dir . '/hello.md', 1_790_000_000);

        $file = $this->directory()->all()[0];

        $this->assertSame('hello', $file->slug);
        $this->assertSame($this->dir . '/hello.md', $file->path);
        $this->assertSame(['title' => 'Hello'], $file->meta);
        $this->assertSame("# Body\n\nText.\n", $file->body, 'the Markdown below the front matter');
        $this->assertEquals(new DateTimeImmutable('@1790000000'), $file->modifiedAt);
        $this->assertNull($file->error);
    }

    /** @return iterable<string, array{string, string}> */
    public static function bodies(): iterable
    {
        yield 'no front matter' => ["# Title\n\nBody.", "# Title\n\nBody."];
        yield 'windows line endings' => ["---\r\ntitle: x\r\n---\r\n\r\nBody.", 'Body.'];
        yield 'nothing below it' => ["---\ntitle: x\n---\n", ''];
        yield 'a rule further down is body' => ["Intro\n\n---\ntitle: no\n---\n", "Intro\n\n---\ntitle: no\n---\n"];
        yield 'an unclosed block is body' => ["---\ntitle: x\nBody.", "---\ntitle: x\nBody."];
    }

    #[DataProvider('bodies')]
    public function test_the_body_is_what_follows_the_front_matter(string $source, string $body): void
    {
        $this->write('post.md', $source);

        $this->assertSame($body, $this->directory()->all()[0]->body);
    }

    public function test_listing_reads_front_matter_and_renders_nothing(): void
    {
        foreach (range(1, 5) as $n) {
            $this->write("post-{$n}.md", "---\ntitle: {$n}\n---\nBody {$n}");
        }

        $this->assertCount(5, $this->directory()->all());
        $this->assertSame(['frontMatter' => 5, 'toHtml' => 0, 'parse' => 0], $this->calls);
    }

    public function test_a_broken_file_is_listed_with_why_and_the_rest_still_are(): void
    {
        $this->write('a.md', "---\ntitle: A\n---\na");
        $this->write('broken.md', "---\nbroken: yes\n---\nstill has a body");
        $this->write('c.md', "---\ntitle: C\n---\nc");

        $files = $this->directory()->all();

        $this->assertSame(['a', 'broken', 'c'], array_map(static fn (ContentFile $f): string => $f->slug, $files));
        $this->assertSame([], $files[1]->meta);
        $this->assertInstanceOf(InvalidFrontMatter::class, $files[1]->error);
        $this->assertSame('still has a body', $files[1]->body);
        $this->assertNull($files[0]->error);
    }

    public function test_an_empty_directory_is_empty(): void
    {
        $this->assertSame([], $this->directory()->all());
    }

    public function test_a_missing_directory_fails_loudly_and_says_which(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($this->dir . '/nope');

        new ContentDirectory($this->dir . '/nope', $this->markdown());
    }

    public function test_a_file_is_not_a_directory(): void
    {
        $this->write('x.md', 'x');

        $this->expectException(InvalidArgumentException::class);

        new ContentDirectory($this->dir . '/x.md', $this->markdown());
    }

    public function test_a_trailing_slash_is_the_same_directory(): void
    {
        $this->write('x.md', 'x');

        $this->assertSame($this->dir . '/x.md', (new ContentDirectory($this->dir . '/', $this->markdown()))->all()[0]->path);
    }

    public function test_find_reads_one_file_by_slug(): void
    {
        $this->write('hello.md', "---\ntitle: Hello\n---\nh");
        $this->write('other.md', "---\ntitle: Other\n---\no");

        $file = $this->directory()->find('hello');

        $this->assertInstanceOf(ContentFile::class, $file);
        $this->assertSame(['title' => 'Hello'], $file->meta);
        $this->assertSame(1, $this->calls['frontMatter'], 'one file read, not the directory');
        $this->assertNull($this->directory()->find('missing'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeSlugs(): iterable
    {
        yield 'parent' => ['../secret'];
        yield 'a path' => ['a/b'];
        yield 'upper case' => ['Hello'];
        yield 'with the extension' => ['hello.md'];
        yield 'empty' => [''];
        yield 'a null byte' => ["hello\0"];
        yield 'a trailing newline' => ["hello\n"];
        yield 'a space' => ['hello world'];
    }

    #[DataProvider('unsafeSlugs')]
    public function test_find_refuses_anything_but_a_slug_before_touching_the_disk(string $slug): void
    {
        $this->write('hello.md', 'h');
        $this->write('secret.md', 's');
        mkdir($this->dir . '/a');
        $this->write('a/b.md', 'b');
        $inner = new ContentDirectory($this->dir . '/a', $this->markdown());

        $this->assertNull($this->directory()->find($slug));
        $this->assertNull($inner->find($slug));
        $this->assertSame(0, $this->calls['frontMatter']);
    }

    public function test_find_ignores_a_directory_named_like_a_post(): void
    {
        mkdir($this->dir . '/post.md');

        $this->assertNull($this->directory()->find('post'));
    }

    public function test_a_document_is_the_meta_and_the_body_rendered(): void
    {
        $this->write('hello.md', "---\ntitle: Hello\n---\nBody");
        $file = $this->directory()->find('hello');
        $this->assertNotNull($file);

        $untrusted = $this->directory()->document($file);
        $trusted = $this->directory()->document($file, trusted: true);

        $this->assertSame(['title' => 'Hello'], $untrusted->meta);
        $this->assertSame('<p data-trusted="no">Body</p>', (string) $untrusted->html);
        $this->assertSame('<p data-trusted="yes">Body</p>', (string) $trusted->html);
    }

    public function test_a_broken_file_has_no_document(): void
    {
        $this->write('broken.md', "---\nbroken: yes\n---\nx");
        $file = $this->directory()->find('broken');
        $this->assertNotNull($file);

        try {
            $this->directory()->document($file);
            $this->fail('A file whose front matter failed must not render.');
        } catch (InvalidFrontMatter $e) {
            $this->assertSame($file->error, $e);
        }
    }

    public function test_an_unreadable_file_is_listed_with_why(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root reads through file permissions.');
        }

        $this->write('locked.md', "---\ntitle: Locked\n---\nx");
        chmod($this->dir . '/locked.md', 0o000);

        $file = $this->directory()->all()[0];
        chmod($this->dir . '/locked.md', 0o644);

        $this->assertSame('locked', $file->slug);
        $this->assertSame([], $file->meta);
        $this->assertInstanceOf(RuntimeException::class, $file->error);
        $this->assertStringContainsString('locked.md', $file->error->getMessage());
    }

    private function directory(): ContentDirectory
    {
        return new ContentDirectory($this->dir, $this->markdown());
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->dir . '/' . $name, $contents);
    }

    /** Counts what it is asked to do; `broken: yes` stands for front matter that won't parse. */
    private function markdown(): MarkdownInterface
    {
        $calls = &$this->calls;

        return new class ($calls) implements MarkdownInterface {
            /** @param array{frontMatter: int, toHtml: int, parse: int} $calls */
            public function __construct(private array &$calls) {}

            public function toHtml(string $markdown, bool $trusted = false): HtmlView
            {
                $this->calls['toHtml']++;

                return new HtmlView('<p data-trusted="' . ($trusted ? 'yes' : 'no') . '">' . $markdown . '</p>');
            }

            public function parse(string $source, bool $trusted = false): Document
            {
                $this->calls['parse']++;

                return new Document([], new HtmlView($source));
            }

            public function frontMatter(string $source): array
            {
                $this->calls['frontMatter']++;

                if (str_contains($source, 'broken: yes')) {
                    throw new InvalidFrontMatter('The front matter is not valid YAML.');
                }

                return preg_match('/^title: (.*)$/m', $source, $m) === 1 && str_starts_with($source, '---') ? ['title' => trim($m[1])] : [];
            }
        };
    }
}
