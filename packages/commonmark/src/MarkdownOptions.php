<?php

declare(strict_types=1);

namespace Hydra\CommonMark;

use InvalidArgumentException;

/**
 * The few things about rendering a site might want to change. What keeps a
 * page safe (escaped raw HTML, refused unsafe links) is not here: it is not
 * a preference.
 */
final readonly class MarkdownOptions
{
    /**
     * @param bool $headingIds give each heading an id a reader can link to
     * @param int $maxNesting deeper blocks are left as text, so a hostile input
     *                        can't recurse its way through the stack
     * @param list<string> $internalHosts links to these hosts are the site's
     *                                    own and keep their referrer
     */
    public function __construct(
        public bool $headingIds = true,
        public int $maxNesting = 20,
        public array $internalHosts = [],
    ) {
        if ($maxNesting < 1) {
            throw new InvalidArgumentException("maxNesting must be at least 1, got {$maxNesting}.");
        }
    }
}
