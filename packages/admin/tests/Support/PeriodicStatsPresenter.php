<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\PeriodAwareInterface;
use Hydra\Admin\Window;

/** The same strip, counting only what the chosen period brought in. */
final class PeriodicStatsPresenter implements PeriodAwareInterface
{
    private ?Window $window = null;

    public function withWindow(Window $window): static
    {
        $clone = clone $this;
        $clone->window = $window;

        return $clone;
    }

    public function present(): array
    {
        return ['stats' => [[
            'label' => 'Accounts',
            'value' => 42,
            'icon' => 'people',
            'caption' => $this->window?->label(),
        ]]];
    }
}
