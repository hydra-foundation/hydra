<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;

/** The barest module that compiles: a source and one field, no screens declared. */
final class DashboardModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('dashboard')
            ->source(ArraySource::class)
            ->fields(Field::text('metric'));
    }
}
