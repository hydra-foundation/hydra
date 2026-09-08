<?php

declare(strict_types=1);

namespace Hydra\View\Contracts;

/**
 * View interface
 *
 * Renders a named template to a string of HTML
 */
interface ViewInterface
{
    /**
     * Render a template to HTML.
     */
    public function render(string $template, array $data = [], bool $layout = true): string;
}
