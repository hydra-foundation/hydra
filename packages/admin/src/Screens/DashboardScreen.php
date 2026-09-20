<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\Widget;

/**
 * A grid of widgets: the landing page of an admin, or any screen whose job is
 * to answer several small questions at once.
 *
 * It renders the cards and nothing in them. Each widget fetches its own body
 * once the grid is on screen, so what the visitor waits for is the layout
 * rather than the slowest query on it, and a widget that fails takes its own
 * card down and leaves the rest of the dashboard standing.
 */
final class DashboardScreen implements ScreenInterface
{
    private string $name = 'dashboard';
    private string $path = '';
    private string $template = 'admin/widgets';
    private ?string $title = null;
    private ?string $ability = null;

    private ?Widget $summary = null;

    /** @var list<Widget> */
    private array $widgets = [];

    private function __construct() {}

    public static function make(): self
    {
        return new self;
    }

    /**
     * A name of its own, for a module with more than one dashboard. The name is
     * what {@see \Hydra\Admin\Blueprint::screen()} answers to and what the
     * widget route points back at.
     */
    public function named(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    /** Path relative to the module root; '' is the module root itself. */
    public function at(string $path): self
    {
        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    public function titled(string $title): self
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    /** @param class-string|null $ability */
    public function requires(?string $ability): self
    {
        $clone = clone $this;
        $clone->ability = $ability;

        return $clone;
    }

    /**
     * A template of your own around the grid. The default draws the cards and
     * nothing else, which is what a dashboard usually wants.
     */
    public function renderedBy(string $template): self
    {
        $clone = clone $this;
        $clone->template = $template;

        return $clone;
    }

    public function widgets(Widget ...$widgets): self
    {
        $clone = clone $this;
        $clone->widgets = [...$this->widgets, ...array_values($widgets)];

        return $clone;
    }

    /**
     * A strip of grand totals above the grid, drawn from one presenter.
     *
     * It sits outside the period entirely: the cards below answer for the
     * stretch of time the visitor picked, and this answers for all of it, so
     * switching the dashboard to today never costs the running totals. That is
     * also why it survives a period change untouched rather than being fetched
     * again with everything else.
     */
    public function summarised(Widget $summary): self
    {
        $clone = clone $this;
        $clone->summary = $summary;

        return $clone;
    }

    public function summary(): ?Widget
    {
        return $this->summary;
    }

    /** @return list<Widget> */
    public function cards(): array
    {
        return $this->widgets;
    }

    /** The summary is reachable at its own URL like any other card. */
    public function card(string $key): ?Widget
    {
        foreach ([...$this->widgets, ...array_filter([$this->summary])] as $widget) {
            if ($widget->key() === $key) {
                return $widget;
            }
        }

        return null;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): array
    {
        return [AdminController::class, 'dashboard'];
    }

    public function ability(): ?string
    {
        return $this->ability;
    }

    public function template(): string
    {
        return $this->template;
    }

    public function heading(): ?string
    {
        return $this->title;
    }
}
