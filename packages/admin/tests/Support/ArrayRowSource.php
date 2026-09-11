<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;

/** Reads rows and single rows, and cannot write either. */
final class ArrayRowSource implements SourceInterface, RowSourceInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [
        '1' => ['id' => 1, 'username' => 'ada'],
        '2' => ['id' => 2, 'username' => 'grace'],
    ];

    public function page(Criteria $criteria): Page
    {
        return new Page(array_values($this->rows), count($this->rows), $criteria);
    }

    public function find(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }
}
