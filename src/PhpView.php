<?php

declare(strict_types=1);

namespace Hydra\View;

use Hydra\Csrf\CsrfGuard;
use Hydra\View\Contracts\ViewInterface;
use RuntimeException;

/**
 * PHP View
 *
 * Native PHP template renderer
 */
final class PhpView implements ViewInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?CsrfGuard $csrf = null,
        private readonly ?string $baseUrl = null,
    ) {}

    public function render(string $template, array $data = [], bool $layout = true): string
    {
        return (new Template($this, $data, $layout, $this->csrf, $this->baseUrl))->resolve($template);
    }

    /**
     * Resolve a template name to a readable file path, contained to the base path.
     */
    public function locate(string $template): string
    {
        // A null byte is never a legitimate template name, and the filesystem
        // calls below would throw a ValueError on it — reject it up front
        // (and don't echo the poisoned name back).
        if (str_contains($template, "\0")) {
            throw new RuntimeException('View not found.');
        }

        $root = realpath($this->basePath);
        $real = $root === false
            ? false
            : realpath($this->basePath . '/' . $template . '.php');

        if (
            $real === false
            || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            || !is_file($real)
        ) {
            throw new RuntimeException("View not found: \"{$template}\".");
        }

        return $real;
    }
}
