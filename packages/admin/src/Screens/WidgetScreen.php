<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ScreenInterface;

/**
 * Where one widget's body comes from, once the grid that holds it has rendered.
 *
 * One route serves every card on a dashboard, named in the path rather than
 * routed per widget: the declaration already says which keys exist, and a route
 * per card would put a dozen lines in `admin:routes` that say the same thing.
 * {@see \Hydra\Admin\Definition::compile()} adds one per dashboard screen.
 */
final class WidgetScreen implements ScreenInterface
{
    /**
     * @param string $dashboard the DashboardScreen's name, which is how the
     *                          controller finds the declaration to render
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
        return $this->dashboard . '.widget';
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
        return [AdminController::class, 'widget'];
    }

    public function ability(): ?string
    {
        return $this->ability;
    }
}
