<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ScreenInterface;

/**
 * One row, read-only. It renders the fields declared for {@see \Hydra\Admin\Surface::Show},
 * which is how a column too heavy for the table — an agent string, a referer —
 * gets somewhere to be read without widening every row.
 */
final class ShowScreen implements ScreenInterface
{
    use RowPath;

    private ?string $title = null;
    private ?string $ability = null;

    private function __construct(private readonly string $path) {}

    /** The detail screen for one row. The path must carry the id the source looks up. */
    public static function make(string $path = '{id}'): self
    {
        return new self(self::rowPath($path));
    }

    public function title(string $title): self
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

    public function name(): string
    {
        return 'show';
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
        return [AdminController::class, 'show'];
    }

    public function ability(): ?string
    {
        return $this->ability;
    }

    public function heading(): ?string
    {
        return $this->title;
    }
}
