<?php

declare(strict_types=1);

namespace Hydra\Seo;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use LengthException;

/**
 * sitemap.xml, from paths and the time each last changed. Built on request
 * from whatever lists the pages, which is the caller's to cache if reading
 * it costs anything; building the XML does not.
 *
 * No priority and no changefreq: Google ignores both, and a field nobody
 * reads still asks whoever fills it to invent a number.
 */
final class Sitemap
{
    /** The protocol's limit for one file; past it, a sitemap index splits the list. */
    private const MAX_URLS = 50_000;

    /** @var array<string, ?DateTimeInterface> lastmod by path, in the order first added */
    private array $urls = [];

    private readonly string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = Url::base($baseUrl, 'Sitemap');
    }

    /**
     * A page. Added again, it stays where it was first listed and keeps the
     * later of the two times, so two sources that both list it cannot
     * duplicate it or date it backwards.
     */
    public function add(string $path, ?DateTimeInterface $lastmod): self
    {
        Url::path($path, 'A sitemap path');

        $known = $this->urls[$path] ?? null;
        if ($known === null || ($lastmod !== null && $lastmod > $known)) {
            $this->urls[$path] = $lastmod;
        }

        return $this;
    }

    /**
     * @template T
     *
     * @param iterable<T>                                               $items
     * @param callable(T): array{string, ?DateTimeInterface} $entry the path and lastmod of one item
     */
    public function addAll(iterable $items, callable $entry): self
    {
        foreach ($items as $item) {
            [$path, $lastmod] = $entry($item);
            $this->add($path, $lastmod);
        }

        return $this;
    }

    /** @throws LengthException past 50,000 URLs */
    public function xml(): string
    {
        if (count($this->urls) > self::MAX_URLS) {
            throw new LengthException(sprintf(
                'A sitemap holds at most %d URLs; split it into a sitemap index (not supported yet).',
                self::MAX_URLS,
            ));
        }

        $utc = new DateTimeZone('UTC');
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($this->urls as $path => $lastmod) {
            $out .= "  <url>\n    <loc>" . Xml::text($this->baseUrl . $path) . "</loc>\n";
            if ($lastmod !== null) {
                $out .= '    <lastmod>' . DateTimeImmutable::createFromInterface($lastmod)->setTimezone($utc)->format(DATE_ATOM) . "</lastmod>\n";
            }
            $out .= "  </url>\n";
        }

        return $out . "</urlset>\n";
    }
}
