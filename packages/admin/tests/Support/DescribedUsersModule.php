<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Link;

/**
 * A module that exercises every declaration `admin:check` reconciles: a
 * sorted column, a searched one, a filtered one and the column a filter link
 * pins, over a source that can be told to disagree about any of them.
 */
final class DescribedUsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->source(DescribedSource::class)
            ->defaultSort('id')
            ->links(Link::make('Admins')->where('role', 'admin'))
            ->fields(
                Field::id()->sortable(),
                Field::text('username')->sortable()->searchable(),
                Field::text('role')->filterable(),
                Field::text('note'),
            );
    }
}
