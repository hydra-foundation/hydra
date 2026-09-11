<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\PageScreen;

/** Ungrouped, and declared first: it stays above every heading. */
final class OverviewModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('overview')
            ->title('Overview')
            ->screens(PageScreen::make('overview', 'admin/dashboard'));
    }
}
