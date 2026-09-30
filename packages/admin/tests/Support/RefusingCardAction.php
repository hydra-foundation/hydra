<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleActionInterface;
use Hydra\Admin\Exceptions\WriteRejected;

/** A button on a card whose action always says no. */
final class RefusingCardAction implements ModuleActionInterface
{
    public function run(): string
    {
        throw WriteRejected::on('action', 'Not now.');
    }
}
