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
    /** Bootstrap's grid, which is what the dashboard lays out on. */
    private const COLUMNS = 12;

    private string $title;
    private ?string $icon = null;
    private int $width = 6;
    private int $refresh = 0;
    private bool $periodic = false;
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
