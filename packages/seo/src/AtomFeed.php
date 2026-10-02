<?php

declare(strict_types=1);

namespace Hydra\Seo;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * An Atom 1.0 feed (RFC 4287), from the feed's details and its entries.
 * Built on request: what the entries come from is the caller's to cache.
 */
final class AtomFeed
{
    /** @var list<FeedEntry> */
    private array $entries = [];

    private readonly string $baseUrl;

    /**
     * @param string              $path     where the feed is served: its rel="self" and its id
     * @param string              $homePath the page the feed is the feed of: its rel="alternate"
     * @param ClockInterface|null $clock    dates an empty feed, which has no entry to take a time from
     */
    public function __construct(
        string $baseUrl,
        private readonly string $path,
        private readonly string $title,
        private readonly string $homePath,
        private readonly string $author,
        private readonly ?string $subtitle = null,
        private readonly ?ClockInterface $clock = null,
    ) {
        $this->baseUrl = Url::base($baseUrl, 'AtomFeed');
        Url::path($path, 'A feed path');
        Url::path($homePath, 'A feed home path');
    }

    public function add(FeedEntry $entry): self
    {
        $this->entries[] = $entry;

        return $this;
    }

    public function xml(): string
    {
        // Newest first, which is what a reader shows and what the feed's own
        // time is taken from. usort is stable, so equal times keep their order.
        $entries = $this->entries;
        usort($entries, static fn (FeedEntry $a, FeedEntry $b): int => $b->updated <=> $a->updated);

        $updated = $entries === []
            ? ($this->clock?->now() ?? new DateTimeImmutable)
            : $entries[0]->updated;

        $self = $this->baseUrl . $this->path;
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n"
            . '  <id>' . Xml::text($self) . "</id>\n"
            . '  <title>' . Xml::text($this->title) . "</title>\n";

        if ($this->subtitle !== null) {
            $out .= '  <subtitle>' . Xml::text($this->subtitle) . "</subtitle>\n";
        }

        $out .= '  <updated>' . self::date($updated) . "</updated>\n"
            . '  <author><name>' . Xml::text($this->author) . "</name></author>\n"
            . '  <link rel="self" type="application/atom+xml" href="' . Xml::text($self) . '"/>' . "\n"
            . '  <link rel="alternate" type="text/html" href="' . Xml::text($this->baseUrl . $this->homePath) . '"/>' . "\n";

        foreach ($entries as $entry) {
            $out .= $this->entry($entry);
        }

        return $out . "</feed>\n";
    }

    private function entry(FeedEntry $entry): string
    {
        $url = Xml::text($this->baseUrl . $entry->path);
        $out = "  <entry>\n"
            . "    <id>$url</id>\n"
            . '    <title>' . Xml::text($entry->title) . "</title>\n"
            . "    <link rel=\"alternate\" type=\"text/html\" href=\"$url\"/>\n"
            . '    <published>' . self::date($entry->published) . "</published>\n"
            . '    <updated>' . self::date($entry->updated) . "</updated>\n"
            . '    <summary>' . Xml::text($entry->summary) . "</summary>\n";

        // Escaped text with type="html", not CDATA: a post containing "]]>"
        // would end a CDATA section early and break the feed.
        if ($entry->content !== null) {
            $out .= '    <content type="html">' . Xml::text($entry->content) . "</content>\n";
        }

        foreach ($entry->categories as $category) {
            $out .= '    <category term="' . Xml::text($category) . '"/>' . "\n";
        }

        return $out . "  </entry>\n";
    }

    private static function date(DateTimeInterface $at): string
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
