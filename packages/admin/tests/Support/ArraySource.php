<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;

/** A read-only source over a fixed row list, paginated the way a real one would be. */
final class ArraySource implements SourceInterface
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows = []) {}

    public function page(Criteria $criteria): Page
    {
        return new Page(
            array_slice($this->rows, $criteria->offset(), $criteria->perPage),
            count($this->rows),
            $criteria,
        );
    }
}
