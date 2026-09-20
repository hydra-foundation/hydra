<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\PeriodAwareInterface;
use Hydra\Admin\Window;

/** A card that answers for a stretch of time, and remembers which one. */
final class TrendPresenter implements PeriodAwareInterface
{
    public ?Window $window = null;

    public function withWindow(Window $window): static
    {
        $clone = clone $this;
        $clone->window = $window;

        return $clone;
    }

    public function present(): array
    {
        return ['total' => $this->window?->label() ?? 'never told'];
    }
}
