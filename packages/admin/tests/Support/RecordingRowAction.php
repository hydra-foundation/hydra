<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\RowActionInterface;
use Hydra\Admin\Exceptions\WriteRejected;

final class RecordingRowAction implements RowActionInterface
{
    /** @var list<string> */
    public array $ran = [];

    public function __construct(private readonly ?string $refusal = null) {}

    public function run(string $id): string
    {
        if ($this->refusal !== null) {
            throw new WriteRejected(['id' => $this->refusal]);
        }

        $this->ran[] = $id;

        return "Row {$id} flagged.";
    }
}
