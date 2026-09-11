<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\FormScreen;

/**
 * A module that writes rows but never opens one: no show screen, so a create
 * has nowhere to land and has to come back to the list instead.
 */
final class CreateOnlyUsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->source(CrudUserSource::class)
            ->fields(Field::id(), Field::text('username'))
            ->screens(
                FormScreen::create()->inputs(Input::text('username')->required()),
            );
    }
}
