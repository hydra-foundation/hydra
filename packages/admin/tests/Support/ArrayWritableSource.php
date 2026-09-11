<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\CreateSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Contracts\UpdateSourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;

final class ArrayWritableSource implements SourceInterface, UpdateSourceInterface, CreateSourceInterface
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

    public function update(string $id, array $data): void
    {
        $this->rows[$id] = [...$this->rows[$id], ...$data];
    }

    public function create(array $data): string
    {
        $id = (string) (count($this->rows) + 1);
        $this->rows[$id] = ['id' => (int) $id, ...$data];

        return $id;
    }
}
