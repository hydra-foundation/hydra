<?php

declare(strict_types=1);

namespace Hydra\Admin\ViewModels;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Period;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Screens\WidgetScreen;
use Hydra\Admin\Widget;

/**
 * What a grid of widgets reads: the cards this visitor may see, where each one
 * fetches its own body from, and the stretch of time they are answering about.
 *
 * $cards is handed in rather than read off the screen, because which cards a
 * visitor may see is the gate's answer and the gate is the controller's. A card
 * kept off the grid is still refused at its own URL — see
 * {@see \Hydra\Admin\AdminController::widget()} — so this is what the visitor
 * is shown, not what they are allowed.
 */
final readonly class DashboardViewModel
{
    /** @param list<Widget> $cards */
    public function __construct(
        public Blueprint $blueprint,
        public DashboardScreen $screen,
        public string $prefix,
        private array $cards = [],
        public Period $period = Period::DEFAULT,
        public ?Widget $summary = null,
    ) {}

    /** @return list<Widget> */
    public function cards(): array
    {
        return $this->cards;
    }

    /**
     * Whether to offer the period control at all. A dashboard whose every card
     * answers for all time has nothing to set.
     */
    public function hasPeriod(): bool
    {
        foreach ($this->cards as $card) {
            if ($card->isPeriodic()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Period> */
    public function periods(): array
    {
        return Period::cases();
    }

    /**
     * Where this card's body comes from, or null when the screen declares no
     * widgets and so was given no route to fetch them over.
     *
     * A periodic card carries the period in its URL, so that the address is the
     * whole question: the same URL fetched twice is the same card, and the
     * refresh button beside it needs to know nothing about the dropdown.
     */
    public function url(Widget $widget): ?string
    {
        $screen = $this->blueprint->screen($this->screen->name() . '.widget');

        if (!$screen instanceof WidgetScreen) {
            return null;
        }

        $url = rtrim($this->prefix, '/') . '/' . $this->blueprint->slug
            . '/' . str_replace('{widget}', rawurlencode($widget->key()), trim($screen->path(), '/'));

        return $widget->isPeriodic() ? $url . '?period=' . rawurlencode($this->period->value) : $url;
    }

    /** The dashboard itself, which is what the period control asks again for. */
    public function dashboardUrl(): string
    {
        $path = trim($this->screen->path(), '/');

        return rtrim($this->prefix, '/') . '/' . $this->blueprint->slug . ($path === '' ? '' : '/' . $path);
    }
}
