<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ExportScreen;
use Hydra\Admin\Surface;

/**
 * A module that exports. Two rows to a page and five rows in the source, so a
 * download that stopped at the page the list shows is visible as one; and a
 * column the table has no room for, so a download that only wrote what the
 * table renders is visible as one too.
 */
final class ExportableUsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->ability('ManageUsers')
            ->source(CrudUserSource::class)
            ->perPage(2)
            ->defaultSort('id', 'asc')
            ->fields(
                Field::id()->sortable(),
                Field::text('username')->sortable()->searchable(),
                Field::text('note')->labelled('Note')->hiddenOn(Surface::List),
                Field::select('role', ['admin' => 'Admin', 'user' => 'User'])->filterable(),
            )
            ->screens(
                ExportScreen::make()->named('people')->labelled('Download CSV'),
            );
    }
}
