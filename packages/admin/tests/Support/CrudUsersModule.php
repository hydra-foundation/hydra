<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;

/**
 * A module declaring every screen, so a controller test can walk one module
 * from the table to a row and back rather than assembling a new one per case.
 */
final class CrudUsersModule implements ModuleInterface
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
                Field::text('note')->hiddenOn(Surface::List),
            )
            ->screens(
                ShowScreen::make()->title('User'),
                FormScreen::create()->title('New user')->inputs(
                    Input::text('username')->required('Enter a username.'),
                ),
                FormScreen::edit()->title('Edit user')->inputs(
                    Input::text('username')->required('Enter a username.'),
                ),
                DeleteScreen::make()->confirm('Delete this user?'),
            );
    }
}
