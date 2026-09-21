<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\FormScreen;

/**
 * A module whose form is made of the controls that are not text: a switch over
 * a stored flag and a radio group over a fixed set, beside the boolean field
 * that reads the flag back on the list.
 */
final class ToggledUsersModule implements ModuleInterface
{
    /** @var array<string, string> */
    public const ROLES = ['admin' => 'Administrator', 'user' => 'User'];

    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->ability('ManageUsers')
            ->source(CrudUserSource::class)
            ->defaultSort('id', 'asc')
            ->fields(
                Field::id()->sortable(),
                Field::text('username'),
                Field::boolean('active', 'Enabled', 'Suspended')->filterable(),
            )
            ->screens(
                FormScreen::create()->title('New user')->inputs(
                    Input::text('username')->required('Enter a username.'),
                    Input::checkbox('active')->asSwitch(),
                    Input::radios('role', self::ROLES),
                ),
                FormScreen::edit()->title('Edit user')->inputs(
                    Input::text('username')->required('Enter a username.'),
                    Input::checkbox('active')->asSwitch(),
                    Input::radios('role', self::ROLES),
                ),
            );
    }
}
