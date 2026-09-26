<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ActionScreen;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ShowScreen;

/** Users with a button on each row and one above the table. */
final class ActionUsersModule implements ModuleInterface
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
            )
            ->screens(
                ShowScreen::make()->title('User'),
                DeleteScreen::make(),
                ActionScreen::row('flag')->confirm('Flag this user?')->runs(RecordingRowAction::class),
                ActionScreen::module('flag-all')->labelled('Flag everyone')->runs(RecordingModuleAction::class),
            );
    }
}
