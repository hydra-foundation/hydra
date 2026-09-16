<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;

/**
 * A source whose only interesting property is what it says about itself, so a
 * test can vary the declaration a module is checked against without writing a
 * new source per case.
 */
final class DescribedSource implements SourceInterface, DescribesColumnsInterface
{
    private SourceDescription $description;

    /**
     * @param list<string>|null $columns
     * @param list<string>|null $sortable
     * @param list<string>|null $searchable
     * @param list<string>|null $filterable
     */
    public function __construct(
        ?array $columns = null,
        ?array $sortable = null,
        ?array $searchable = null,
        ?array $filterable = null,
        string $defaultSort = 'id',
        string $table = 'users',
    ) {
        $this->description = new SourceDescription(
            table: $table,
            columns: $columns ?? ['id', 'username', 'role', 'note'],
            sortable: $sortable ?? ['id', 'username'],
            searchable: $searchable ?? ['username'],
            filterable: $filterable ?? ['role'],
            defaultSort: $defaultSort,
        );
    }

    public function describe(): SourceDescription
    {
        return $this->description;
    }

    public function page(Criteria $criteria): Page
    {
        return new Page([], 0, $criteria);
    }
}
