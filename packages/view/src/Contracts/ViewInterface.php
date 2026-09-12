<?php

declare(strict_types=1);

namespace Hydra\View\Contracts;

/**
 * Renders a named template to a string of HTML
 */
interface ViewInterface
{
    /**
     * Render a template to HTML.
     */
    public function render(string $template, array $data = [], bool $layout = true): string;

    /**
     * Whether the template resolves, without rendering it. For asking ahead of
     * time — a tool checking that what an application declared can actually be
     * shown — rather than for choosing between templates at render time.
     */
    public function has(string $template): bool;
}
