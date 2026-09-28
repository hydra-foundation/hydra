<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ShowScreen;

/**
 * The Files module as an application declares it, shown with the same image
 * and file fields that make any module a holder of references: which is the
 * case FileSource has to keep out of its own count.
 */
final class StoredFilesModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('files')
            ->source('files.source')
            ->defaultSort('modified_at', 'desc')
            ->fields(
                Field::id(),
                Field::image('key')->nameFrom('name'),
                Field::file('download')->nameFrom('name'),
                Field::text('name')->sortable()->searchable(),
                Field::text('disk')->filterable(),
                Field::text('kind')->filterable(),
                Field::text('status')->filterable(),
                Field::number('size')->sortable(),
                Field::datetime('modified_at')->sortable(),
            )
            ->screens(ShowScreen::make(), DeleteScreen::make());
    }
}
