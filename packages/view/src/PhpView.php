<?php

declare(strict_types=1);

namespace Hydra\View;

use Hydra\Csrf\CsrfGuard;
use Hydra\Http\CspNonce;
use Hydra\View\Contracts\ViewInterface;
use RuntimeException;

/**
 * Native PHP template renderer. A template name is looked for in the base path
 * first and in the fallbacks after it, so a package can ship templates of its
 * own and the application can replace any one of them with a file of the same
 * name in its own views directory. Nothing is registered for that: the copy
 * simply wins.
 */
final class PhpView implements ViewInterface
{
    /** @var list<string> The base path, then the fallbacks, in search order. */
    private readonly array $paths;

    /**
     * @param list<string> $fallbacks searched in order when the base path has no such template
     * @param array<string, mixed> $shared data every render begins with
     */
    public function __construct(
        string $basePath,
        private readonly ?CsrfGuard $csrf = null,
        private readonly ?string $baseUrl = null,
        array $fallbacks = [],
        private readonly array $shared = [],
        private readonly ?CspNonce $cspNonce = null,
    ) {
        $this->paths = [$basePath, ...array_values($fallbacks)];
    }

    /**
     * Shared data reaches partials too, which the parent's own data deliberately
     * does not: it belongs to the view rather than to any one render, and a
     * layout or partial that needs it cannot be handed it by a caller that does
     * not know it exists. A render's own data still wins on a name collision.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], bool $layout = true): string
    {
        $data = [...$this->shared, ...$data];

        return (new Template($this, $data, $layout, $this->csrf, $this->baseUrl, $this->cspNonce))->resolve($template);
    }

    public function has(string $template): bool
    {
        try {
            $this->locate($template);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a template name to a readable file path, contained to the views
     * directory it was found in.
     */
    public function locate(string $template): string
    {
        // A null byte is never a legitimate template name, and the filesystem
        // calls below would throw a ValueError on it, so reject it up front
        // (and don't echo the poisoned name back).
        if (str_contains($template, "\0")) {
            throw new RuntimeException('View not found.');
        }

        foreach ($this->paths as $path) {
            $file = $this->under($path, $template);

            if ($file !== null) {
                return $file;
            }
        }

        throw new RuntimeException("View not found: \"{$template}\".");
    }

    /**
     * The template's file inside this views directory, or null when it is not
     * there, including when the name climbs out of it: that is not this
     * directory's template no matter what the next one holds.
     */
    private function under(string $path, string $template): ?string
    {
        $root = realpath($path);
        $real = $root === false
            ? false
            : realpath($path . '/' . $template . '.php');

        if (
            $real === false
            || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            || !is_file($real)
        ) {
            return null;
        }

        return $real;
    }
}
