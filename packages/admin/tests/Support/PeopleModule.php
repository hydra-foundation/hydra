<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;

/** Grouped, and the first of its group to be declared. */
final class PeopleModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('people')
            ->title('People')
            ->ability('ManageThings')
            ->group('Administration')
            ->source(ArraySource::class)
            ->fields(Field::id());
    }
}
