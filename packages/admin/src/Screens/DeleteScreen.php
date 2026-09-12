<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ScreenInterface;

/**
 * The one screen with nothing to look at: it is a POST and an answer. A GET that
 * removed a row would be followed by anything that crawls links, so the button
 * that reaches this is a form, and the confirmation is the client's job.
 */
final class DeleteScreen implements ScreenInterface
{
    use RowPath;

    private const CONFIRM = 'Delete this row? This cannot be undone.';

    private string $confirm = self::CONFIRM;
    private ?string $ability = null;

    private function __construct(private readonly string $path) {}

    /** The path must carry the id the source deletes. */
    public static function make(string $path = '{id}/delete'): self
    {
        return new self(self::rowPath($path));
    }

    /** What the visitor is asked before the row goes. */
    public function confirm(string $confirm): self
    {
        $clone = clone $this;
        $clone->confirm = $confirm;

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
        return 'delete';
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
        return [AdminController::class, 'destroy'];
    }

    public function ability(): ?string
    {
        return $this->ability;
    }

    public function prompt(): string
    {
        return $this->confirm;
    }
}
