<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Http\FieldReader;
use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\Accepted;
use Hydra\Validation\Rules\Nullable;
use Hydra\Validation\Rules\Required;
use LogicException;

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
    private bool $switch = false;

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

    /**
     * The same one-of-these choice a select makes, with every option on the
     * page at once. Worth the vertical space for a short, stable set.
     *
     * @param array<string, string> $options
     */
    public static function radios(string $name, array $options): self
    {
        return new self($name, InputType::Radios, $options);
    }

    /** A single yes/no, stored as 1 or 0. */
    public static function checkbox(string $name): self
    {
        return new self($name, InputType::Checkbox);
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

    /**
     * A checkbox is required by being ticked, not by being submitted: unticked
     * it arrives as "0", which is present, so {@see Required} would pass it and
     * {@see Accepted} is the rule that means what the asterisk promises.
     */
    public function required(string $message = 'This field is required.'): self
    {
        $clone = $this->rules($this->type === InputType::Checkbox
            ? new Accepted($message === 'This field is required.' ? 'This must be ticked.' : $message)
            : new Required($message));
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

    /**
     * The same checkbox, drawn as a sliding toggle. Presentation and nothing
     * else: it posts, validates and stores as the box it still is.
     */
    public function asSwitch(): self
    {
        if ($this->type !== InputType::Checkbox) {
            throw new LogicException(sprintf(
                'Input "%s" is a %s; only a checkbox can be drawn as a switch.',
                $this->name,
                $this->type->value,
            ));
        }

        $clone = clone $this;
        $clone->switch = true;

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

    public function isSwitch(): bool
    {
        return $this->switch;
    }

    /**
     * The rules to check this submission against. An optional control leads
     * with {@see Nullable}, so a length rule on an optional password means "if
     * you are changing it, make it long enough", not "you must change it".
     *
     * @return list<RuleInterface>
     */
    public function ruleSet(): array
    {
        return $this->required ? $this->rules : [new Nullable, ...$this->rules];
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

    /**
     * Whether the box is ticked for these values: the checkbox counterpart to
     * valueFrom(), because a tick is not a string the template can echo.
     *
     * @param array<string, mixed> $values
     */
    public function isChecked(array $values): bool
    {
        return Flag::of($values[$this->name] ?? null);
    }

    /**
     * This control's value as the submission holds it. A checkbox is why the
     * controller cannot simply read the body: an unticked box submits nothing
     * at all, and absence has to become "0" rather than the empty string a
     * cleared text field leaves behind.
     */
    public function submittedValue(FieldReader $body): string
    {
        if ($this->type === InputType::Checkbox) {
            return Flag::of($body->string($this->name)) ? '1' : '0';
        }

        return trim($body->string($this->name));
    }
}
