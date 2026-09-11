<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;

/** The same group, declared after a module belonging to another. */
final class AuditModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('audit')
            ->title('Audit')
            ->ability('ManageThings')
            ->group('Administration')
            ->source(ArraySource::class)
            ->fields(Field::id());
    }
}
