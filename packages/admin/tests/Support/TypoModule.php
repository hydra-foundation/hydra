<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\PageScreen;

/** A page screen naming a template nobody ships: the mistake worth catching. */
final class TypoModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('reports')
            ->title('Reports')
            ->screens(PageScreen::make('overview', 'admin/reprots'));
    }
}
