<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\View\Contracts\MarkdownInterface;
use Hydra\View\Document;
use Hydra\View\HtmlView;
use Hydra\View\InvalidFrontMatter;

/**
 * Enough of a front-matter reader for a content source's tests, without the
 * commonmark package: `key: value` lines, where `[a, b]` is a list, `true`
 * and `false` are booleans, a bare day is a date, and a line with no colon
 * is the malformed YAML a real parser would refuse.
 */
final class LineFrontMatter implements MarkdownInterface
{
    public function toHtml(string $markdown, bool $trusted = false): HtmlView
    {
        return new HtmlView('<p>' . htmlspecialchars($markdown) . '</p>');
    }

    public function parse(string $source, bool $trusted = false): Document
    {
        return new Document($this->frontMatter($source), $this->toHtml($source, $trusted));
    }

    public function frontMatter(string $source): array
    {
        if (preg_match('/\A---\n(.*?)\n---\n/s', $source, $block) !== 1) {
            return [];
        }

        $meta = [];

        foreach (explode("\n", $block[1]) as $line) {
            if (!str_contains($line, ': ')) {
                throw new InvalidFrontMatter("The front matter is not valid YAML at \"{$line}\".");
            }

            [$key, $value] = explode(': ', $line, 2);
            $meta[$key] = match (true) {
                $value === 'true', $value === 'false' => $value === 'true',
                preg_match('/^\[(.*)\]$/', $value, $list) === 1 => $list[1] === '' ? [] : explode(', ', $list[1]),
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 => new DateTimeImmutable($value, new DateTimeZone('UTC')),
                is_numeric($value) => (int) $value,
                default => $value,
            };
        }

        return $meta;
    }
}
