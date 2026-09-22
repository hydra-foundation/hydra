<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;

/**
 * A source that says it filters by role and either does or does not.
 *
 * The second is the whole defect in one object: the description is what
 * `admin:check` reads, so a source built this way passes that command, renders
 * its toolbar, and answers every filtered request with all five rows.
 */
final class AdvertisedFilterSource implements SourceInterface, DescribesColumnsInterface
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    public function __construct(private readonly bool $applies = true)
    {
        foreach (['ada', 'grace', 'alan', 'edsger', 'barbara'] as $index => $username) {
            $this->rows[] = [
                'id' => $index + 1,
                'username' => $username,
                'role' => $index < 2 ? 'admin' : 'user',
            ];
        }
    }

    public function describe(): SourceDescription
    {
        return new SourceDescription(
            table: 'users',
            columns: ['id', 'username', 'role'],
            sortable: ['id', 'username'],
            searchable: [],
            filterable: ['role'],
            defaultSort: 'id',
        );
    }

    public function page(Criteria $criteria): Page
    {
        $rows = $this->rows;

        if ($this->applies) {
            foreach ($criteria->filters as $column => $value) {
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => (string) ($row[$column] ?? '') === $value,
                ));
            }
        }

        if ($criteria->direction === 'desc') {
            $rows = array_reverse($rows);
        }

        return new Page(array_slice($rows, $criteria->offset(), $criteria->perPage), count($rows), $criteria);
    }
}
