<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\PageScreen;

/**
 * A landing page and nothing else: no source, no fields, no presenter. The
 * shape an application declares to get the shipped dashboard.
 */
final class LandingModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('home')
            ->title('Home')
            ->icon('speedometer')
            ->screens(PageScreen::make('overview', 'admin/dashboard'));
    }
}
