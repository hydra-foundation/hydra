<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;

/** The Posts module from the content-files recipe, over PostSource. */
final class PostsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('posts')
            ->title('Posts')
            ->source(PostSource::class)
            ->defaultSort('date', 'desc')
            ->fields(
                Field::id(),
                Field::text('title')->sortable()->searchable(),
                Field::date('date')->sortable()->filterable(),
                Field::text('tags')->searchable()->filterable(),
                Field::select('status', ['published' => 'Published', 'scheduled' => 'Scheduled', 'draft' => 'Draft'])->filterable(),
                Field::text('problem'),
                Field::text('body')->onlyOn(Surface::Show),
            )
            ->screens(ShowScreen::make());
    }
}
