<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Closure;
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
    private string $label = 'Delete';
    private ?string $ability = null;

    /** @var (Closure(array<string, mixed>): bool)|null */
    private ?Closure $shows = null;

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

    /** The button's word, for a delete that is really a cancel or a dismiss. */
    public function labelled(string $label): self
    {
        $clone = clone $this;
        $clone->label = $label;

        return $clone;
    }

    /**
     * Which rows get the button. Only the button: the source still decides
     * whether the row may go, since it can change between the page and the click.
     *
     * @param Closure(array<string, mixed>): bool $shows
     */
    public function when(Closure $shows): self
    {
        $clone = clone $this;
        $clone->shows = $shows;

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

    public function label(): string
    {
        return $this->label;
    }

    /** @param array<string, mixed> $row */
    public function shows(array $row): bool
    {
        return $this->shows === null || ($this->shows)($row);
    }
}
