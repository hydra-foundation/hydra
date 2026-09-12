<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\Contracts\SubmittableInterface;
use Hydra\Admin\Input;

/**
 * A row, writable: create() opens a blank one, edit() opens one that exists. Its
 * controls are declared here rather than on the Definition because create and
 * edit are not the same form — a password is required on one and optional on the
 * other — and because an Input is not a display projection of a Field.
 */
final class FormScreen implements ScreenInterface, SubmittableInterface
{
    use RowPath;

    private ?string $title = null;
    private ?string $ability = null;

    /** @var list<Input> */
    private array $inputs = [];

    private function __construct(
        private readonly string $name,
        private readonly string $path,
        private readonly string $action,
        private readonly string $submit,
    ) {}

    /** The blank form. Its path names no row, because there is not one yet. */
    public static function create(string $path = 'new'): self
    {
        return new self('create', $path, 'create', 'store');
    }

    /** The edit form for one row. The path must carry the id the source looks up. */
    public static function edit(string $path = '{id}/edit'): self
    {
        return new self('edit', self::rowPath($path), 'edit', 'update');
    }

    public function inputs(Input ...$inputs): self
    {
        $clone = clone $this;
        $clone->inputs = [...$this->inputs, ...array_values($inputs)];

        return $clone;
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
        return $this->name;
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
        return [AdminController::class, $this->action];
    }

    public function submitHandler(): array
    {
        return [AdminController::class, $this->submit];
    }

    public function ability(): ?string
    {
        return $this->ability;
    }

    public function heading(): ?string
    {
        return $this->title;
    }

    /** @return list<Input> */
    public function controls(): array
    {
        return $this->inputs;
    }

    /**
     * The rule set {@see \Hydra\Validation\Validator} wants, keyed by input name.
     * Built against the submission because a blank optional control is exempt.
     *
     * @param array<string, mixed> $values
     * @return array<string, list<\Hydra\Validation\Contracts\RuleInterface>>
     */
    public function rulesFor(array $values): array
    {
        $rules = [];

        foreach ($this->inputs as $input) {
            $rules[$input->name()] = $input->rulesFor($values[$input->name()] ?? null);
        }

        return $rules;
    }
}
