<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Closure;
use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ModuleActionInterface;
use Hydra\Admin\Contracts\RowActionInterface;
use Hydra\Admin\Contracts\ScreenInterface;
use LogicException;

/**
 * A button that does one thing, as a POST: to one row, at {id}/<name>, or to the
 * module, at <name>. Like a delete it has nothing to look at, and the work is in
 * the class it runs rather than in the source.
 */
final class ActionScreen implements ScreenInterface
{
    /** Names the admin looks for to decide whether to add a screen of its own. */
    private const RESERVED = ['list', 'counts'];

    private ?string $label = null;
    private ?string $prompt = null;
    private ?string $ability = null;

    /** @var class-string<RowActionInterface|ModuleActionInterface>|null */
    private ?string $action = null;

    /** @var (Closure(array<string, mixed>): bool)|null */
    private ?Closure $shows = null;

    private function __construct(
        private readonly string $name,
        private readonly bool $rowScoped,
    ) {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name) !== 1) {
            throw new LogicException(
                "An action is named in lower-case letters, digits and dashes, since the name is its path; \"{$name}\" is not."
            );
        }

        if (in_array($name, self::RESERVED, true)) {
            throw new LogicException("An action cannot be named \"{$name}\": the admin names a screen of its own that.");
        }
    }

    /** A button on each row and on its detail screen. */
    public static function row(string $name): self
    {
        return new self($name, true);
    }

    /** A button above the table, for the module as a whole. */
    public static function module(string $name): self
    {
        return new self($name, false);
    }

    /** @param class-string $action */
    public function runs(string $action): self
    {
        $contract = $this->rowScoped ? RowActionInterface::class : ModuleActionInterface::class;

        if (!is_subclass_of($action, $contract)) {
            throw new LogicException("The action \"{$this->name}\" must run a {$contract}; {$action} is not one.");
        }

        $clone = clone $this;
        $clone->action = $action;

        return $clone;
    }

    public function labelled(string $label): self
    {
        $clone = clone $this;
        $clone->label = $label;

        return $clone;
    }

    /** What the visitor is asked before it runs. Nothing is asked without one. */
    public function confirm(string $prompt): self
    {
        $clone = clone $this;
        $clone->prompt = $prompt;

        return $clone;
    }

    /**
     * Which rows get the button. Only the button: the action still decides
     * whether it may run, since the row can change between the page and the click.
     *
     * @param Closure(array<string, mixed>): bool $shows
     */
    public function when(Closure $shows): self
    {
        if (!$this->rowScoped) {
            throw new LogicException("\"{$this->name}\" is a module action, so there is no row for when() to ask about.");
        }

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
        return $this->name;
    }

    public function method(): string
    {
        return 'POST';
    }

    public function path(): string
    {
        return $this->rowScoped ? '{id}/' . $this->name : $this->name;
    }

    public function handler(): array
    {
        return [AdminController::class, 'act'];
    }

    public function ability(): ?string
    {
        return $this->ability;
    }

    public function isRowScoped(): bool
    {
        return $this->rowScoped;
    }

    /** @return class-string<RowActionInterface|ModuleActionInterface>|null */
    public function action(): ?string
    {
        return $this->action;
    }

    public function label(): string
    {
        return $this->label ?? ucfirst(str_replace('-', ' ', $this->name));
    }

    public function prompt(): ?string
    {
        return $this->prompt;
    }

    /** @param array<string, mixed> $row */
    public function shows(array $row): bool
    {
        return $this->shows === null || ($this->shows)($row);
    }
}
