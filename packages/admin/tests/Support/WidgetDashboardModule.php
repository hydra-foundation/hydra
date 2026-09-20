<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Widget;

/**
 * A dashboard of cards, one of them behind an ability of its own, one that
 * follows the period, and a strip of totals above them that does not.
 */
final class WidgetDashboardModule implements ModuleInterface
{
    public function __construct(private readonly bool $periodic = true) {}

    public function define(): Definition
    {
        return Definition::make('overview')
            ->title('Overview')
            ->screens(
                DashboardScreen::make()
                    ->titled('Overview')
                    ->summarised(
                        Widget::make('totals', 'admin/partials/stats')
                            ->titled('Totals')
                            ->from(StatsPresenter::class),
                    )
                    ->widgets(
                        Widget::make('accounts', 'admin/widgets/count')
                            ->titled('Accounts')
                            ->withIcon('people')
                            ->spanning(4)
                            ->from(CountPresenter::class),
                        Widget::make('live', 'admin/widgets/count')
                            ->titled('Live')
                            ->refreshEvery(30)
                            ->from(CountPresenter::class),
                        Widget::make('secret', 'admin/widgets/count')
                            ->titled('Secret')
                            ->requires('ManageBilling')
                            ->from(CountPresenter::class),
                        ...($this->periodic ? [
                            Widget::make('trend', 'admin/widgets/count')
                                ->titled('Trend')
                                ->periodic()
                                ->from(TrendPresenter::class),
                        ] : []),
                    ),
            );
    }
}
