<?php

declare(strict_types=1);

namespace Hydra\Seo;

use DateTimeInterface;
use InvalidArgumentException;

/** One post in an Atom feed. Its URL is its id, so it stays stable as long as the URL does. */
final class FeedEntry
{
    /**
     * @param string       $summary    plain text, shown by readers that do not render content
     * @param string|null  $content    the post as HTML, or null for a summary-only feed
     * @param list<string> $categories one per tag
     */
    public function __construct(
        public readonly string $path,
        public readonly string $title,
        public readonly DateTimeInterface $published,
        public readonly DateTimeInterface $updated,
        public readonly string $summary,
        public readonly ?string $content = null,
        public readonly array $categories = [],
    ) {
        Url::path($path, 'A feed entry path');

        if (trim($title) === '') {
            throw new InvalidArgumentException(sprintf('The feed entry %s has no title.', $path));
        }
    }
}
