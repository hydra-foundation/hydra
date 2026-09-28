<?php

declare(strict_types=1);

namespace Hydra\Tools\Changes;

/**
 * One-off: split the wiki's hand-written changelog page into release files.
 *
 * Each `<h2 id="vX-Y-Z">X.Y.Z &mdash; Title</h2>` and the `<p class="relmeta">`
 * under it become the front matter; the HTML up to the next `<h2>` is the
 * body, kept as HTML. Anything else headed by an `<h2>` ("Earlier") and the
 * page's own intro are not releases and are left behind.
 *
 * Two changes to the bodies, both so they still read right on a page of
 * their own and through a markdown renderer:
 * - a link to another release on the same page (`#v0-4-0`) points at that
 *   release's page (`0.4.0.html`), since the anchor no longer exists;
 * - the page's indentation is removed, because a line indented four spaces
 *   after a blank line is a code block in markdown.
 */
final class Migrator
{
    private const HEADING = '#<h2 id="([^"]*)">(.*?)</h2>#s';
    private const RELEASE_ID = '/^v(\d+)-(\d+)-(\d+)$/';
    private const RELMETA = '#^\s*<p class="relmeta">(.*?)</p>#s';

    /**
     * @return list<ReleaseFile> in page order, newest first
     */
    public static function split(string $html): array
    {
        $start = strpos($html, '<article');
        $end = strrpos($html, '</article>');
        $article = $start === false || $end === false ? $html : substr($html, $start, $end - $start);

        preg_match_all(self::HEADING, $article, $headings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $releases = [];
        foreach ($headings as $i => $heading) {
            $id = $heading[1][0];
            if (preg_match(self::RELEASE_ID, $id, $parts) !== 1) {
                continue;
            }

            $bodyStart = $heading[0][1] + strlen($heading[0][0]);
            $bodyEnd = $headings[$i + 1][0][1] ?? strlen($article);
            $releases[] = self::release("$parts[1].$parts[2].$parts[3]", $id, $heading[2][0], substr($article, $bodyStart, $bodyEnd - $bodyStart));
        }

        if ($releases === []) {
            throw new \UnexpectedValueException('no <h2 id="v…"> release headings on the page; is this the changelog?');
        }

        return $releases;
    }

    private static function release(string $version, string $id, string $heading, string $rest): ReleaseFile
    {
        [$headed, $title] = array_pad(explode('&mdash;', $heading, 2), 2, '');
        if (trim($headed) !== $version) {
            throw new \UnexpectedValueException("$id is headed " . trim($headed) . "; the id and the heading should name one version");
        }

        if (preg_match(self::RELMETA, $rest, $meta) !== 1) {
            throw new \UnexpectedValueException("$version has no <p class=\"relmeta\"> under its heading, so it has no date");
        }
        // "2026&#8209;09&#8209;27 &middot; <a …>compare</a>": the date is
        // the first part, written with non-breaking hyphens.
        $date = str_replace("\u{2011}", '-', trim(self::text(explode('&middot;', $meta[1])[0])));

        $body = self::relink(self::dedent(substr($rest, strlen($meta[0]))));

        return new ReleaseFile($version, self::text($title), $date, false, $body);
    }

    private static function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Removes the page's indentation, except inside <pre>, where whitespace
     * is content (and where the page writes code at column 0, which would
     * otherwise make the common indent nothing).
     */
    private static function dedent(string $html): string
    {
        $lines = explode("\n", trim($html, "\n"));

        // true for each line whose whitespace is <pre> content: the lines
        // after a <pre> opens, through the one that closes it.
        $verbatim = [];
        $inPre = false;
        foreach ($lines as $i => $line) {
            $verbatim[$i] = $inPre;
            if (!$inPre && str_contains($line, '<pre')) {
                $inPre = !str_contains($line, '</pre>');
                $verbatim[$i] = null;
            } elseif ($inPre && str_contains($line, '</pre>')) {
                $inPre = false;
            }
        }

        $indent = PHP_INT_MAX;
        foreach ($lines as $i => $line) {
            if ($verbatim[$i] === false && trim($line) !== '') {
                $indent = min($indent, self::indent($line));
            }
        }
        $indent = $indent === PHP_INT_MAX ? 0 : $indent;

        $out = [];
        foreach ($lines as $i => $line) {
            if ($verbatim[$i] === true) {
                $out[] = $line;
                continue;
            }
            if ($verbatim[$i] === null && $out !== [] && end($out) !== '') {
                // A <pre> opening a line starts a markdown HTML block that
                // only </pre> ends, blank lines inside it included. Nested
                // in the <div> above, it would not.
                $out[] = '';
            }
            $out[] = trim($line) === '' ? '' : rtrim(substr($line, min($indent, self::indent($line))));
        }

        return trim(implode("\n", $out));
    }

    private static function indent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    private static function relink(string $html): string
    {
        return (string) preg_replace_callback(
            '#href="(?:/docs/changelog\.html)?\#v(\d+)-(\d+)-(\d+)"#',
            static fn (array $m): string => "href=\"$m[1].$m[2].$m[3].html\"",
            $html,
        );
    }
}
