<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\PageScreen;

/**
 * A module carrying both kinds of screen, so one fixture covers a generated form
 * and a page screen naming its own template.
 */
final class EditableUsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->source(ArrayWritableSource::class)
            ->fields(Field::id(), Field::text('username')->sortable())
            ->screens(
                PageScreen::make('new', 'admin/new')->at('new'),
                FormScreen::edit()->inputs(Input::text('username')->required()),
            );
    }
}
