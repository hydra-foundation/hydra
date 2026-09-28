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

/** Users with a picture: one file control, on the create and edit forms alike, that keeps the name it was uploaded as. */
final class AvatarUsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->ability('ManageUsers')
            ->source(CrudUserSource::class)
            ->defaultSort('id', 'asc')
            ->fields(
                Field::id()->sortable(),
                Field::image('avatar')->fallbackIcon('person-circle')->nameFrom('avatar_name'),
                Field::text('username'),
            )
            ->screens(
                ShowScreen::make(),
                FormScreen::create()->title('New user')->inputs(
                    Input::text('username')->required('Enter a username.'),
                    $this->avatar(),
                ),
                FormScreen::edit()->title('Edit user')->inputs(
                    Input::text('username')->required('Enter a username.'),
                    $this->avatar(),
                ),
                DeleteScreen::make(),
            );
    }

    private function avatar(): Input
    {
        return Input::file('avatar')
            ->storedIn('avatars')
            ->accepts('image/png', 'image/jpeg')
            ->maxSize(1024)
            ->removable()
            ->keepsName()
            ->help('PNG or JPEG, up to 1 KB.');
    }
}
