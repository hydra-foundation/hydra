<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Link;
use Hydra\Admin\Screens\ExportScreen;

/**
 * A log of things that happened, narrowed by day: the arrangement a date
 * filter has to survive, with a filter link and an exact filter beside it and
 * an export behind it.
 */
final class DatedModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('events')
            ->source(DatedSource::class)
            ->defaultSort('id')
            ->perPage(1)
            ->links(
                Link::make('All'),
                Link::make('Errors')->where('kind', 'error'),
            )
            ->fields(
                Field::id(),
                Field::text('subject'),
                Field::select('kind', ['info' => 'Info', 'error' => 'Error'])->filterable(),
                Field::datetime('happened_at')->labelled('Happened')->sortable()->filterable(),
            )
            ->screens(ExportScreen::make());
    }
}
