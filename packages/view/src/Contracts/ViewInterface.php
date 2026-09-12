<?php

declare(strict_types=1);

namespace Hydra\View\Contracts;

/**
 * The seam between a controller and whatever turns a template name into HTML, so
 * a controller never touches the filesystem or the templating engine itself.
 */
interface ViewInterface
{
    /**
     * A false $layout renders the template's body alone, ignoring any layout it
     * extends, which is what an htmx fragment response wants.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], bool $layout = true): string;

    /**
     * Whether the template resolves, without rendering it. For asking ahead of
     * time (a tool checking that what an application declared can actually be
     * shown) rather than for choosing between templates at render time.
     */
    public function has(string $template): bool;
}
