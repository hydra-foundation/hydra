<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;

/**
 * A source that keeps the criteria it was handed, for the tests that are about
 * how something reads a source rather than about what comes back. The total it
 * reports is separable from what it holds, because a real source is allowed not
 * to count and anything walking one has to cope.
 */
final class RecordingSource implements SourceInterface
{
    /** @var list<Criteria> */
    public array $asked = [];

    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?int $total = null,
    ) {}

    public function page(Criteria $criteria): Page
    {
        $this->asked[] = $criteria;

        return new Page(
            array_slice($this->rows, $criteria->offset(), $criteria->perPage),
            $this->total ?? count($this->rows),
            $criteria,
        );
    }
}
