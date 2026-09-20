<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\PeriodAwareInterface;
use Hydra\Admin\Contracts\PresenterInterface;
use LogicException;

/**
 * One card on a dashboard: what it is called, how wide it sits, the template
 * that draws its body and the presenter that fills it.
 *
 * The presenter is a service id resolved per request, the way a module's source
 * and a page screen's presenter are, so a dashboard of a dozen widgets opens no
 * connections at boot. Each widget fetches itself once the grid is on screen,
 * which is the point of declaring them separately at all: a slow query costs
 * its own card and not the page.
 */
final class Widget
{
    /** The grid the dashboard lays out on. */
    private const COLUMNS = 12;

    /** Beyond this a placeholder is taller than anything it stands in for. */
    private const ROWS = 12;

    private string $title;
    private ?string $icon = null;
    private int $width = 6;
    private int $refresh = 0;
    private int $reserve = 3;
    private Shape $shape = Shape::Lines;
    private bool $periodic = false;
    private bool $refreshable = false;
    private ?string $ability = null;
    private ?string $presenter = null;

    private function __construct(
        private readonly string $key,
        private string $template,
    ) {
        $this->title = ucfirst(str_replace(['-', '_'], ' ', $key));
    }

    /** $template draws the card's body; the card itself the admin ships. */
    public static function make(string $key, string $template): self
    {
        if ($key === '') {
            throw new LogicException('An admin widget has no key to live at.');
        }

        return new self($key, $template);
    }

    public function titled(string $title): self
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    public function withIcon(string $icon): self
    {
        $clone = clone $this;
        $clone->icon = $icon;

        return $clone;
    }

    /** How many of the grid's twelve columns the card takes on a wide screen. */
    public function spanning(int $width): self
    {
        $clone = clone $this;
        $clone->width = min(max(1, $width), self::COLUMNS);

        return $clone;
    }

    /**
     * How many items of body to hold open while the card fetches itself.
     *
     * Every card starts empty and grows to whatever its query returns, and the
     * grid reflows under each one as it lands. An item is whatever {@see
     * shaped()} says one is, so this is the same number as the LIMIT the
     * presenter runs under — a card showing six paths reserves six — and not a
     * count of abstract bars arrived at by squinting at the result.
     *
     * It is a declaration and not a measurement, so it can be wrong. Wrong by
     * an item is a small settle; not declared at all is the whole dashboard
     * jumping twice a second while five cards land.
     */
    public function reserving(int $items): self
    {
        $clone = clone $this;
        $clone->reserve = min(max(1, $items), self::ROWS);

        return $clone;
    }

    /**
     * What one of those items looks like, which is what gives the count a
     * height. {@see Shape} for the four, and why a count alone was not enough.
     */
    public function shaped(Shape $shape): self
    {
        $clone = clone $this;
        $clone->shape = $shape;

        return $clone;
    }

    /**
     * How often the card fetches itself again, in seconds. Zero, the default,
     * is once: most numbers on a dashboard are not worth a request a minute,
     * and the ones that are say so here.
     */
    public function refreshEvery(int $seconds): self
    {
        $clone = clone $this;
        $clone->refresh = max(0, $seconds);

        return $clone;
    }

    /**
     * Offer a button that asks this card again, now.
     *
     * Off by default, and that is the correction: every card carried one, so a
     * dashboard of six offered six controls each doing a sixth of a job, none
     * of them the page's. The dashboard's own refresh is beside the period, and
     * a card earns one of its own only where "a moment ago" is a real answer —
     * which is why polling implies it.
     */
    public function refreshable(): self
    {
        $clone = clone $this;
        $clone->refreshable = true;

        return $clone;
    }

    /**
     * The card answers for the period the dashboard is set to, and redraws when
     * that changes. Its presenter has to implement {@see PeriodAwareInterface}
     * to be told what the period is.
     *
     * A card that says nothing here is a card about all of time: it is fetched
     * once and left alone, which is what a running total wants.
     */
    public function periodic(): self
    {
        $clone = clone $this;
        $clone->periodic = true;

        return $clone;
    }

    /**
     * An ability of the card's own, narrower than the screen it sits on. A
     * visitor without it is not shown the card and cannot fetch its body.
     *
     * @param class-string|null $ability
     */
    public function requires(?string $ability): self
    {
        $clone = clone $this;
        $clone->ability = $ability;

        return $clone;
    }

    /** @param class-string $presenter a {@see PresenterInterface} service id */
    public function from(string $presenter): self
    {
        $clone = clone $this;
        $clone->presenter = $presenter;

        return $clone;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function icon(): ?string
    {
        return $this->icon;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function refresh(): int
    {
        return $this->refresh;
    }

    public function reserve(): int
    {
        return $this->reserve;
    }

    public function shape(): Shape
    {
        return $this->shape;
    }

    /** A card that polls is a card where now matters, so it offers the button too. */
    public function isRefreshable(): bool
    {
        return $this->refreshable || $this->refresh > 0;
    }

    public function isPeriodic(): bool
    {
        return $this->periodic;
    }

    public function template(): string
    {
        return $this->template;
    }

    /** @return class-string|null */
    public function presenter(): ?string
    {
        return $this->presenter;
    }

    public function ability(): ?string
    {
        return $this->ability;
    }
}
