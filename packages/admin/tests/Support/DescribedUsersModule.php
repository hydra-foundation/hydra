<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;

/**
 * A module that exercises all three declarations `admin:check` reconciles: a
 * sorted column, a searched one and a filtered one, over a source that can be
 * told to disagree about any of them.
 */
final class DescribedUsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->source(DescribedSource::class)
            ->defaultSort('id')
            ->fields(
                Field::id()->sortable(),
                Field::text('username')->sortable()->searchable(),
                Field::text('role')->filterable(),
                Field::text('note'),
            );
    }
}
