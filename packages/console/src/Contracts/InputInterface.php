<?php

declare(strict_types=1);

namespace Hydra\Console\Contracts;

/**
 * What a command was typed with.
 *
 * Typed accessors rather than a raw bag, the same bargain {@see \Hydra\Http\FieldReader}
 * makes over a request: the caller states the shape it wants and gets it or the
 * default, because an argument that was never typed is null and `(string) null`
 * is a deprecation in one PHP version and an error in the next.
 */
interface InputInterface
{
    /** Whether the argument was typed at all, as against typed empty. */
    public function hasArgument(string $name): bool;

    /** A positional argument, or $default when it was not typed. */
    public function argument(string $name, string $default = ''): string;

    /** A `--name=value` option, or $default when it was not given. */
    public function option(string $name, string $default = ''): string;

    /**
     * Whether a flag was given. Always a bool, including for an option the
     * command never declared — a caller asking about an unknown flag is asking
     * whether the user typed it, and the answer to that is no.
     */
    public function flag(string $name): bool;
}
