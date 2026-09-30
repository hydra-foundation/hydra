<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleActionInterface;

/** A button on a card that counts its presses. */
final class CardAction implements ModuleActionInterface
{
    public int $runs = 0;

    public function run(): string
    {
        ++$this->runs;

        return 'Emptied.';
    }
}
