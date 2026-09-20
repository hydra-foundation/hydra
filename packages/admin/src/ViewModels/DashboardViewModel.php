<?php

declare(strict_types=1);

namespace Hydra\Admin\ViewModels;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Period;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Screens\WidgetScreen;
use Hydra\Admin\Widget;
use Hydra\Admin\Window;

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
        /**
         * The period resolved against the clock, when the caller has one to
         * hand. Null where only the choice is needed and not the dates it came
         * out as — the card URLs carry the key, not the bounds.
         */
        public ?Window $window = null,
    ) {}

    /** @return list<Widget> */
    public function cards(): array
    {
        return $this->cards;
    }

    /**
     * Whether to offer the period control at all. A dashboard whose every card
     * answers for all time has nothing to set.
     *
     * The strip counts. It is not in the grid, but it is on the page, and a
     * page whose headline figures follow the period and whose cards do not
     * still has a period — without this it would have had no way to set it.
     */
    public function hasPeriod(): bool
    {
        if ($this->summary?->isPeriodic()) {
            return true;
        }

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

    /**
     * The same dashboard at the period showing. The select sends its own value
     * and so needs no period on the URL; a button has no value to send, and
     * without this one asking again would quietly reset the page to today.
     */
    public function refreshUrl(): string
    {
        return $this->dashboardUrl() . '?period=' . rawurlencode($this->period->value);
    }
}
