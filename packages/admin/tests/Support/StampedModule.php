<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ExportScreen;
use Hydra\Admin\Screens\ShowScreen;

/** A module with a timestamp on every surface that can render one. */
final class StampedModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('stamps')
            ->source(StampedSource::class)
            ->fields(
                Field::id(),
                Field::text('username'),
                Field::datetime('created_at')->labelled('Created'),
            )
            ->screens(ShowScreen::make(), ExportScreen::make());
    }
}
