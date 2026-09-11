<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;

final class UsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->ability('AccessAdmin')
            ->source(ArraySource::class)
            ->defaultSort('id')
            ->fields(
                Field::id(),
                Field::text('username')->sortable()->searchable(),
            );
    }
}
