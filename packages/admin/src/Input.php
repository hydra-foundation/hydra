<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Filesystem\Key;
use Hydra\Http\FieldReader;
use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\Accepted;
use Hydra\Validation\Rules\MaxFileSize;
use Hydra\Validation\Rules\MimeType;
use Hydra\Validation\Rules\Nullable;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Rules\UploadedFile;
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

    private ?string $directory = null;
    private bool $public = false;
    private bool $removable = false;
    private ?string $nameColumn = null;

    /** @var list<string> */
    private array $accepted = [];

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

    /**
     * A file. The source never sees the upload: the admin stores it and hands
     * the source the qualified key it was stored under ("private:avatars/…"),
     * a string like any other column.
     */
    public static function file(string $name): self
    {
        return new self($name, InputType::File);
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

    /**
     * The directory on the disk to store into. Private unless $public: a file
     * on the public disk is served to anyone who has its URL.
     */
    public function storedIn(string $directory, bool $public = false): self
    {
        $clone = $this->onlyFile(__FUNCTION__);

        try {
            $clone->directory = Key::valid($directory);
        } catch (InvalidKey $invalid) {
            throw new LogicException(sprintf('Input "%s": %s', $this->name, $invalid->getMessage()), 0, $invalid);
        }

        $clone->public = $public;

        return $clone;
    }

    /**
     * The types to take, checked against the bytes on the server. The same
     * list goes to the browser as accept=, which only narrows the file picker.
     */
    public function accepts(string ...$types): self
    {
        $clone = $this->onlyFile(__FUNCTION__)->rules(new MimeType(...$types));
        $clone->accepted = array_values($types);

        return $clone;
    }

    public function maxSize(int $bytes): self
    {
        return $this->onlyFile(__FUNCTION__)->rules(new MaxFileSize($bytes));
    }

    /**
     * Offer a box that clears the stored file. Without one, a file can only
     * be replaced, since an empty file input means "keep what is there".
     */
    public function removable(bool $removable = true): self
    {
        $clone = $this->onlyFile(__FUNCTION__);
        $clone->removable = $removable;

        return $clone;
    }

    /**
     * Keep the name the file was uploaded under, in $column beside the key
     * ("{name}_name" when none is given). The source is handed it on the same
     * write as the key, so it has to be a column the source writes. Without
     * this, the name is not kept and a download is called what its key is.
     */
    public function keepsName(?string $column = null): self
    {
        $clone = $this->onlyFile(__FUNCTION__);
        $clone->nameColumn = $column ?? $this->name . '_name';

        if ($clone->nameColumn === $this->name) {
            throw new LogicException(sprintf(
                'Input "%s" cannot keep its file\'s name in the column that holds its key.',
                $this->name,
            ));
        }

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

    public function isFile(): bool
    {
        return $this->type === InputType::File;
    }

    public function directory(): string
    {
        return $this->directory ?? $this->name;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    /** @return list<string> */
    public function accepted(): array
    {
        return $this->accepted;
    }

    public function isRemovable(): bool
    {
        return $this->removable;
    }

    /** Where the uploaded file's name is kept, or null when it is not. */
    public function nameColumn(): ?string
    {
        return $this->nameColumn;
    }

    /** The name the remove box posts under. */
    public function removeName(): string
    {
        return $this->name . '_remove';
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
        // A file control checks the upload arrived whole before anything asks
        // about its size or type, so a failed upload is reported as that.
        $rules = $this->isFile() ? [new UploadedFile, ...$this->rules] : $this->rules;

        return $this->required ? $rules : [new Nullable, ...$rules];
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

    /** A modifier that only a file control has any meaning for. */
    private function onlyFile(string $method): self
    {
        if ($this->type !== InputType::File) {
            throw new LogicException(sprintf(
                'Input "%s" is a %s; %s() is a file control\'s.',
                $this->name,
                $this->type->value,
                $method,
            ));
        }

        return clone $this;
    }
}
