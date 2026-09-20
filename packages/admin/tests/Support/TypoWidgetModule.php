<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Widget;

/**
 * A widget naming a template nobody ships. No route names it — the widget route
 * serves every card — so this is the mistake `admin:routes` has to go looking
 * for rather than trip over.
 */
final class TypoWidgetModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('overview')
            ->screens(
                DashboardScreen::make()->widgets(
                    Widget::make('counts', 'admin/widgets/cuonts'),
                ),
            );
    }
}
