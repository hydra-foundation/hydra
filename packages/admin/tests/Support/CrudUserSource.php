<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\CreateSourceInterface;
use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Contracts\UpdateSourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\Page;

/**
 * An in-memory source that can do everything the contracts allow, including
 * refuse: the name "taken" is spoken for, which is how a test reaches the
 * branch a real source reaches on a unique index.
 */
final class CrudUserSource implements SourceInterface, RowSourceInterface, UpdateSourceInterface, CreateSourceInterface, DeleteSourceInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public int $nextId = 1;

    public function __construct()
    {
        foreach (['ada', 'grace', 'alan', 'edsger', 'barbara'] as $username) {
            $this->create(['username' => $username, 'note' => "about {$username}"]);
        }
    }

    public function page(Criteria $criteria): Page
    {
        $rows = array_values($this->rows);

        if ($criteria->search !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => str_contains((string) $row['username'], $criteria->search),
            ));
        }

        usort($rows, function (array $a, array $b) use ($criteria): int {
            $key = $criteria->sort ?? 'id';
            $order = $a[$key] <=> $b[$key];

            return $criteria->direction === 'desc' ? -$order : $order;
        });

        return new Page(
            array_slice($rows, $criteria->offset(), $criteria->perPage),
            count($rows),
            $criteria,
        );
    }

    public function find(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function create(array $data): string
    {
        $this->reject($data);
        $id = (string) $this->nextId++;
        $this->rows[$id] = ['id' => (int) $id, 'username' => '', 'note' => '', ...$data];

        return $id;
    }

    public function update(string $id, array $data): void
    {
        $this->reject($data);
        $this->rows[$id] = [...$this->rows[$id], ...$data];
    }

    public function delete(string $id): void
    {
        if ($id === '1') {
            throw WriteRejected::on('id', 'The first user cannot be deleted.');
        }

        unset($this->rows[$id]);
    }

    /** @param array<string, mixed> $data */
    private function reject(array $data): void
    {
        if (($data['username'] ?? null) === 'taken') {
            throw WriteRejected::on('username', 'That username is already taken.');
        }
    }
}
