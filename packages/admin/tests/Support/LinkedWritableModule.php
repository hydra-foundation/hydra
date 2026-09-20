<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Link;
use Hydra\Admin\Screens\FormScreen;

/**
 * Writable and linked at once, which is the one arrangement where a write and a
 * tally the browser is still holding can contradict each other.
 */
final class LinkedWritableModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->source(ArrayWritableSource::class)
            ->fields(Field::id(), Field::text('username')->sortable())
            ->links(
                Link::make('All'),
                Link::make('Ada')->where('username', 'ada'),
            )
            ->screens(FormScreen::edit()->inputs(Input::text('username')->required()));
    }
}
