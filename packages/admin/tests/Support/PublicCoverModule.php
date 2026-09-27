<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\FormScreen;

/** A file bound for the public disk, the way a blog's cover image would be. */
final class PublicCoverModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->ability('ManageUsers')
            ->source(CrudUserSource::class)
            ->fields(Field::id(), Field::text('username'))
            ->screens(
                FormScreen::edit()->inputs(
                    Input::text('username')->required(),
                    Input::file('cover')->storedIn('blog', public: true)->accepts('image/png'),
                ),
            );
    }
}
