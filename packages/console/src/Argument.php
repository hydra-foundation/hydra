<?php

declare(strict_types=1);

namespace Hydra\Console;

/**
 * One positional argument a command accepts.
 *
 * Arguments are ordered: a command declares them in the order they are typed,
 * and an optional one cannot precede a required one. That rule belongs to
 * whatever parses the command line rather than here, but declaring them in a
 * list rather than by name is what makes the order expressible at all.
 */
final readonly class Argument
{
    public function __construct(
        public string $name,
        public string $description = '',
        public bool $required = true,
        public ?string $default = null,
    ) {}

    /** A required argument: the command cannot run without it. */
    public static function required(string $name, string $description = ''): self
    {
        return new self($name, $description);
    }

    /** An optional argument, falling back to $default when it is not typed. */
    public static function optional(string $name, string $description = '', ?string $default = null): self
    {
        return new self($name, $description, required: false, default: $default);
    }
}
