<?php

declare(strict_types=1);

namespace Hydra\Tools\Changes;

/**
 * One change, written in the commit that makes it: `changes/unreleased/<slug>.md`.
 *
 * The body is the note (a list item in the release) and an optional
 * `### Upgrading` part, which the release collects above everything else
 * because it is the one thing a reader has to act on.
 */
final class Fragment
{
    /**
     * In the order a section lists them: what a reader must not miss first.
     */
    public const KINDS = ['security', 'removed', 'changed', 'added', 'fixed'];

    private const SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
    private const UPGRADING = '/^###[ \t]+Upgrading[ \t]*$/m';

    public function __construct(
        public readonly string $slug,
        public readonly string $section,
        public readonly string $kind,
        public readonly string $note,
        public readonly ?string $upgrading = null,
    ) {}

    public static function fromFile(string $path): self
    {
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new InvalidChangeFile($path, null, 'cannot be read');
        }

        return self::parse($text, $path);
    }

    public static function parse(string $text, string $file): self
    {
        $slug = basename($file, '.md');
        if (!preg_match(self::SLUG, $slug)) {
            throw new InvalidChangeFile($file, null, "'$slug' is not a kebab-case slug; name the file like file-uploads.md");
        }

        $matter = FrontMatter::parse($text, $file);
        $fields = $matter->fields;

        foreach (array_keys($fields) as $key) {
            if ($key !== 'section' && $key !== 'kind') {
                throw new InvalidChangeFile($file, $matter->lines[$key], "unknown key '$key'; a fragment has section and kind");
            }
        }
        foreach (['section', 'kind'] as $key) {
            if (!isset($fields[$key])) {
                throw new InvalidChangeFile($file, 1, "missing '$key'");
            }
        }
        if (!in_array($fields['kind'], self::KINDS, true)) {
            throw new InvalidChangeFile($file, $matter->lines['kind'], "'{$fields['kind']}' is not a kind; use one of " . implode(', ', self::KINDS));
        }

        [$note, $upgrading] = self::split($matter, $file);

        return new self($slug, $fields['section'], $fields['kind'], $note, $upgrading);
    }

    /**
     * @return array{string, string|null}
     */
    private static function split(FrontMatter $matter, string $file): array
    {
        $body = $matter->body;
        preg_match_all(self::UPGRADING, $body, $matches, PREG_OFFSET_CAPTURE);
        $offsets = array_column($matches[0], 1);

        $lineOf = static fn (int $offset): int => $matter->bodyLine + substr_count($body, "\n", 0, $offset);

        if (count($offsets) > 1) {
            throw new InvalidChangeFile($file, $lineOf($offsets[1]), 'a second ### Upgrading; put everything to do under the first');
        }

        $note = trim($offsets === [] ? $body : substr($body, 0, $offsets[0]));
        if ($note === '') {
            throw new InvalidChangeFile($file, $matter->bodyLine, 'no note: say what changed, in a sentence or two, under the front matter');
        }
        if ($offsets === []) {
            return [$note, null];
        }

        // Past the heading's own line; the rest of the body is what to do.
        $end = strpos($body, "\n", $offsets[0]);
        $upgrading = $end === false ? '' : trim(substr($body, $end));
        if ($upgrading === '') {
            throw new InvalidChangeFile($file, $lineOf($offsets[0]), '### Upgrading is empty; say what a consumer has to do, or drop the heading');
        }

        return [$note, $upgrading];
    }
}
