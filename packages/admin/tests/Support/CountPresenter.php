<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\PresenterInterface;

/** A widget's data, and a record of whether anything asked for it. */
final class CountPresenter implements PresenterInterface
{
    public int $calls = 0;

    public function __construct(private readonly int $total = 7) {}

    public function present(): array
    {
        ++$this->calls;

        return ['total' => $this->total];
    }
}
