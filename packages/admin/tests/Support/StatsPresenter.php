<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\PresenterInterface;

/** Grand totals for a summary strip: no window, because it counts all of them. */
final class StatsPresenter implements PresenterInterface
{
    public int $calls = 0;

    public function present(): array
    {
        ++$this->calls;

        return ['stats' => [['label' => 'Accounts', 'value' => 42, 'icon' => 'people']]];
    }
}
