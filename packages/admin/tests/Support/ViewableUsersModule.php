<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;

/**
 * A module with a show screen, including a field hidden from the list, which is
 * the case a show screen exists for.
 */
final class ViewableUsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->source(ArrayRowSource::class)
            ->fields(
                Field::id(),
                Field::text('username'),
                Field::text('user_agent')->labelled('Agent')->hiddenOn(Surface::List),
            )
            ->screens(ShowScreen::make());
    }
}
