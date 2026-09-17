<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Admin\Modules;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\PageScreen;
use Hydra\Tests\Fixture\Admin\Presenters\DashboardPresenter;

/**
 * The other half of the module space: no source, no fields, one page screen,
 * and no ability, so it is what every signed-in visitor can reach. It is also
 * the landing module, which is what makes /admin a redirect rather than a page.
 */
final class DashboardModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('dashboard')
            ->title('Dashboard')
            ->group('Overview')
            ->icon('grid-1x2')
            ->screens(
                PageScreen::make('overview', 'admin/dashboard')
                    ->presentedBy(DashboardPresenter::class),
            );
    }
}
