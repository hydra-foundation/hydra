<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;

/** A module with no id field, so a reference to one of its rows can name no row. */
final class UnnumberedModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('unnumbered')
            ->source('unnumbered.source')
            ->fields(Field::file('doc'));
    }
}
