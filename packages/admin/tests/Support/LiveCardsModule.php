<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Widget;

/** A dashboard whose cards listen, poll, do both, or do neither. */
final class LiveCardsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('live')
            ->title('Live')
            ->screens(
                DashboardScreen::make()
                    ->summarised(
                        Widget::make('totals', 'admin/widgets/count')
                            ->titled('Totals')
                            ->liveOn('module.users')
                            ->from(CountPresenter::class),
                    )
                    ->widgets(
                        Widget::make('still', 'admin/widgets/count')->titled('Still')->from(CountPresenter::class),
                        Widget::make('listening', 'admin/widgets/count')
                            ->titled('Listening')
                            ->liveOn('module.users')
                            ->from(CountPresenter::class),
                        Widget::make('both', 'admin/widgets/count')
                            ->titled('Both')
                            ->refreshEvery(30)
                            ->liveOn('module.users', 'module.jobs')
                            ->liveOn('module.users')
                            ->from(CountPresenter::class),
                    ),
            );
    }
}
