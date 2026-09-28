<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;

/**
 * Files the app stored and the admin only shows: no form, so the keys are
 * declared by the read-side fields alone.
 */
final class DocumentsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('documents')
            ->source('documents.source')
            ->fields(
                Field::id(),
                Field::file('attachment')->nameFrom('attachment_name'),
                Field::image('cover'),
            );
    }
}
