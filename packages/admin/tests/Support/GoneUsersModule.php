<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\ShowScreen;

final class GoneUsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->source(CrudUserSource::class)
            ->gone('That user has already left.')
            ->fields(
                Field::id()->sortable(),
                Field::text('username'),
            )
            ->screens(
                ShowScreen::make()->title('User'),
                FormScreen::edit()->title('Edit user')->inputs(
                    Input::text('username')->required('Enter a username.'),
                ),
            );
    }
}
