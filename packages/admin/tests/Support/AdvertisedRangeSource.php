<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use DateTimeImmutable;
use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;

/**
 * A source that says it narrows by the day somebody joined, and either does or
 * does not: {@see AdvertisedFilterSource}'s defect, for a range.
 */
final class AdvertisedRangeSource implements SourceInterface, DescribesColumnsInterface
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    public function __construct(private readonly bool $applies = true)
    {
        foreach (['ada', 'grace', 'alan', 'edsger', 'barbara'] as $index => $username) {
            $this->rows[] = [
                'id' => $index + 1,
                'username' => $username,
                'joined_at' => sprintf('2026-10-0%d 12:00:00', $index + 1),
            ];
        }
    }

    public function describe(): SourceDescription
    {
        return new SourceDescription(
            table: 'users',
            columns: ['id', 'username', 'joined_at'],
            sortable: ['id', 'username'],
            searchable: [],
            filterable: ['joined_at'],
            defaultSort: 'id',
        );
    }

    public function page(Criteria $criteria): Page
    {
        $rows = $this->rows;
        $range = $criteria->ranges['joined_at'] ?? null;

        if ($this->applies && $range !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $range->contains(new DateTimeImmutable($row['joined_at'] . ' UTC')),
            ));
        }

        if ($criteria->direction === 'desc') {
            $rows = array_reverse($rows);
        }

        return new Page(array_slice($rows, $criteria->offset(), $criteria->perPage), count($rows), $criteria);
    }
}
