<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Widget;

/**
 * A dashboard whose cards carry buttons: one with two, one behind an ability
 * of its own, and one with none, which must not grow a route or a footer.
 */
final class ActionCardsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('health')
            ->title('Health')
            ->screens(
                DashboardScreen::make()
                    ->titled('Health')
                    ->widgets(
                        Widget::make('cache', 'admin/widgets/count')
                            ->titled('Cache')
                            ->from(CountPresenter::class)
                            ->action('flush', 'Flush', CardAction::class, confirm: 'Empty the cache?')
                            ->action('stall', 'Stall', RefusingCardAction::class),
                        Widget::make('vault', 'admin/widgets/count')
                            ->titled('Vault')
                            ->requires('ManageVault')
                            ->from(CountPresenter::class)
                            ->action('seal', 'Seal', CardAction::class),
                        Widget::make('plain', 'admin/widgets/count')
                            ->titled('Plain')
                            ->from(CountPresenter::class),
                    ),
            );
    }
}
