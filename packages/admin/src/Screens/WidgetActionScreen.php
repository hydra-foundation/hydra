<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ScreenInterface;

/**
 * Where a card's buttons post: one route for every button on a dashboard,
 * with the card and the button named in the path, as {@see WidgetScreen} is
 * one route for every card. {@see \Hydra\Admin\Definition::compile()} adds it
 * to a dashboard with at least one card that has an action.
 */
final class WidgetActionScreen implements ScreenInterface
{
    /**
     * @param string $dashboard the DashboardScreen's name
     * @param class-string|null $ability
     */
    public function __construct(
        private readonly string $dashboard,
        private readonly string $path,
        private readonly ?string $ability = null,
    ) {}

    /** The dashboard whose cards this answers for. */
    public function dashboard(): string
    {
        return $this->dashboard;
    }

    public function name(): string
    {
        return $this->dashboard . '.widget-action';
    }

    public function method(): string
    {
        return 'POST';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): array
    {
        return [AdminController::class, 'widgetAction'];
    }

    public function ability(): ?string
    {
        return $this->ability;
    }
}
