<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Shape;
use Hydra\Admin\Widget;

/**
 * A dashboard of cards, one of them behind an ability of its own, one that
 * follows the period, and a strip of totals above them.
 *
 * Both halves are switchable, because they decide different things. Whether
 * any card follows the period decides whether the page offers one at all;
 * whether the strip does decides what a change of period has to send back,
 * since the strip renders outside the region that change replaces.
 */
final class WidgetDashboardModule implements ModuleInterface
{
    public function __construct(
        private readonly bool $periodic = true,
        private readonly bool $summaryPeriodic = false,
    ) {}

    public function define(): Definition
    {
        $totals = $this->summaryPeriodic
            ? Widget::make('totals', 'admin/partials/stats')->periodic()->from(PeriodicStatsPresenter::class)
            : Widget::make('totals', 'admin/partials/stats')->from(StatsPresenter::class);

        return Definition::make('overview')
            ->title('Overview')
            ->screens(
                DashboardScreen::make()
                    ->titled('Overview')
                    ->summarised($totals->titled('Totals'))
                    ->widgets(
                        Widget::make('accounts', 'admin/widgets/count')
                            ->titled('Accounts')
                            ->withIcon('people')
                            ->spanning(4)
                            ->reserving(6)
                            ->shaped(Shape::Bars)
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
