<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ExportScreen;

/** A module whose export refuses to hand over more than two rows at a time. */
final class CappedUsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->source(CrudUserSource::class)
            ->defaultSort('id', 'asc')
            ->fields(Field::id(), Field::text('username'))
            ->screens(ExportScreen::make()->limit(2));
    }
}
