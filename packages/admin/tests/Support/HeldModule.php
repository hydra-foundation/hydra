<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;

/** A module whose source answers for its own files. */
final class HeldModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('held')
            ->source('held.source')
            ->fields(Field::id(), Field::image('picture'));
    }
}
