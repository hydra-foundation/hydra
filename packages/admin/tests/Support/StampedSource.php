<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;

/**
 * Rows holding instants as a database holds them: UTC, to the second, as
 * strings. The evening one is the case the whole feature is about — it is
 * already tomorrow in UTC while it is still today for the person reading.
 */
final class StampedSource implements SourceInterface, RowSourceInterface
{
    /** @var array<array-key, array<string, mixed>> */
    public array $rows = [
        '1' => ['id' => 1, 'username' => 'ada', 'created_at' => '2026-01-01T09:30:00+00:00'],
        '2' => ['id' => 2, 'username' => 'grace', 'created_at' => '2026-01-02T03:15:00+00:00'],
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
