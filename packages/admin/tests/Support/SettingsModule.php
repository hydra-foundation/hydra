<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;

/** A second group, so the order groups appear in is observable. */
final class SettingsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('settings')
            ->title('Settings')
            ->ability('ManageThings')
            ->group('System')
            ->source(ArraySource::class)
            ->fields(Field::id());
    }
}
