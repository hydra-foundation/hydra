<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;

/** A module that holds no file, over a source that fails if it is ever read. */
final class NoFilesModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('plain')
            ->source('plain.source')
            ->fields(Field::id(), Field::text('title'));
    }
}
