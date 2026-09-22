<?php

declare(strict_types=1);

namespace Hydra\Console;

/**
 * One `--option` a command accepts, in the only two shapes anything here uses:
 * a flag that is present or absent, and one that carries a value.
 *
 * Built through the named constructors rather than the constructor, because
 * the difference between the two is the whole of an option's behaviour and a
 * boolean argument at the call site states it far too quietly — `true` in
 * `new Option('force', 'f', '', true)` could as easily read as "required".
 */
final readonly class Option
{
    private function __construct(
        public string $name,
        public ?string $shortcut,
        public string $description,
        public bool $takesValue,
        public ?string $default,
    ) {}

    /**
     * A flag: `--force` or nothing. Reading it gives a bool, and it never
     * carries a value, so `--force=no` is an error rather than a quiet false.
     */
    public static function flag(string $name, ?string $shortcut = null, string $description = ''): self
    {
        return new self($name, $shortcut, $description, takesValue: false, default: null);
    }

    /** An option that carries a value: `--table=posts`. */
    public static function value(
        string $name,
        ?string $shortcut = null,
        string $description = '',
        ?string $default = null,
    ): self {
        return new self($name, $shortcut, $description, takesValue: true, default: $default);
    }
}
