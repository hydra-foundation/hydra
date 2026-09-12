<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\Required;

/**
 * One writable control on a form screen. The counterpart to {@see Field}, and
 * deliberately not the same object: a Field formats a value for reading, an
 * Input has to hand the stored value back unchanged so a submit round-trips.
 */
final class Input
{
    private string $label;
    private ?string $help = null;
    private bool $readonly = false;
    private bool $required = false;

    /** @var list<RuleInterface> */
    private array $rules = [];

    /** @param array<string, string>|null $options */
    private function __construct(
        private readonly string $name,
        private readonly InputType $type,
        private readonly ?array $options = null,
    ) {
        $this->label = ucfirst(str_replace('_', ' ', $name));
    }

    public static function text(string $name): self
    {
        return new self($name, InputType::Text);
    }

    public static function email(string $name): self
    {
        return new self($name, InputType::Email);
    }

    public static function password(string $name): self
    {
        return new self($name, InputType::Password);
    }

    public static function textarea(string $name): self
    {
        return new self($name, InputType::Textarea);
    }

    /** @param array<string, string> $options */
    public static function select(string $name, array $options): self
    {
        return new self($name, InputType::Select, $options);
    }

    public function labelled(string $label): self
    {
        $clone = clone $this;
        $clone->label = $label;

        return $clone;
    }

    /** Shown under the control. The place to say "leave blank to keep". */
    public function help(string $help): self
    {
        $clone = clone $this;
        $clone->help = $help;

        return $clone;
    }

    public function required(string $message = 'This field is required.'): self
    {
        $clone = $this->rules(new Required($message));
        $clone->required = true;

        return $clone;
    }

    public function rules(RuleInterface ...$rules): self
    {
        $clone = clone $this;
        $clone->rules = [...$this->rules, ...array_values($rules)];

        return $clone;
    }

    public function readonly(bool $readonly = true): self
    {
        $clone = clone $this;
        $clone->readonly = $readonly;

        return $clone;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): InputType
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function hint(): ?string
    {
        return $this->help;
    }

    /** @return array<string, string>|null */
    public function options(): ?array
    {
        return $this->options;
    }

    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * The rules to check this submission against. An optional control left blank
     * is not checked at all: a length rule on an optional password means "if you
     * are changing it, make it long enough", not "you must change it".
     *
     * @return list<RuleInterface>
     */
    public function rulesFor(mixed $value): array
    {
        if (!$this->required && ($value === null || $value === '')) {
            return [];
        }

        return $this->rules;
    }

    /**
     * The value to put in the control: what the row stores, never a formatted
     * reading of it, so submitting an untouched form is a no-op. A password is
     * never handed back, so an edit form always starts blank.
     *
     * @param array<string, mixed> $values
     */
    public function valueFrom(array $values): string
    {
        if ($this->type === InputType::Password) {
            return '';
        }

        $value = $values[$this->name] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
