<?php

declare(strict_types=1);

namespace Hydra\View\Content;

use DateTimeImmutable;
use Hydra\View\Contracts\MarkdownInterface;
use Hydra\View\Document;
use InvalidArgumentException;
use RuntimeException;

/**
 * A directory of Markdown files with front matter, such as a blog's posts
 * kept in git. Each `*.md` whose name is a slug (`[a-z0-9-]+`) is a file;
 * anything else is skipped, since it couldn't be linked to anyway.
 *
 * Listing reads front matter only. A body is rendered when document() asks,
 * so a list of two hundred posts renders none of them. Nothing is cached:
 * reading the front matter of a few hundred small files takes milliseconds.
 */
final class ContentDirectory
{
    private const SLUG = '/^[a-z0-9-]+$/D';

    /**
     * The block CommonMark's front-matter parser reads, and any newlines
     * after it, so the body here is the body a full parse renders.
     */
    private const FRONT_MATTER = '/\A---\R.*?\R---\R\R*/s';

    /** The directory, without a trailing slash. */
    public readonly string $directory;

    /**
     * @throws InvalidArgumentException when $directory is not a directory: a
     *                                  typo in the path fails here, rather than
     *                                  showing an empty list forever
     */
    public function __construct(string $directory, private readonly MarkdownInterface $markdown)
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException("Content directory {$directory} does not exist.");
        }

        $this->directory = rtrim($directory, '/');
    }

    /** @return list<ContentFile> every file, by slug */
    public function all(): array
    {
        $files = [];

        foreach (scandir($this->directory) ?: [] as $name) {
            $slug = substr($name, 0, -3);

            if (str_ends_with($name, '.md') && preg_match(self::SLUG, $slug) === 1 && is_file($this->path($slug))) {
                $files[] = $this->read($slug);
            }
        }

        usort($files, static fn (ContentFile $a, ContentFile $b): int => strcmp($a->slug, $b->slug));

        return $files;
    }

    /** One file by slug, or null. Anything that isn't a slug never reaches the disk. */
    public function find(string $slug): ?ContentFile
    {
        if (preg_match(self::SLUG, $slug) !== 1 || !is_file($this->path($slug))) {
            return null;
        }

        return $this->read($slug);
    }

    /**
     * The file's front matter and its body as HTML.
     *
     * @throws RuntimeException the error the file was read with, if any
     */
    public function document(ContentFile $file, bool $trusted = false): Document
    {
        if ($file->error !== null) {
            throw $file->error;
        }

        return new Document($file->meta, $this->markdown->toHtml($file->body, $trusted));
    }

    private function read(string $slug): ContentFile
    {
        $path = $this->path($slug);
        $modifiedAt = new DateTimeImmutable('@' . (int) filemtime($path));
        $source = @file_get_contents($path);

        if ($source === false) {
            return new ContentFile($slug, $path, $modifiedAt, [], '', new RuntimeException("{$path} could not be read."));
        }

        $body = (string) preg_replace(self::FRONT_MATTER, '', $source);

        try {
            return new ContentFile($slug, $path, $modifiedAt, $this->markdown->frontMatter($source), $body);
        } catch (RuntimeException $e) {
            return new ContentFile($slug, $path, $modifiedAt, [], $body, $e);
        }
    }

    private function path(string $slug): string
    {
        return "{$this->directory}/{$slug}.md";
    }
}
