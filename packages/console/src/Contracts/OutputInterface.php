<?php

declare(strict_types=1);

namespace Hydra\Console\Contracts;

/**
 * Everything a command may say, and everything it may ask.
 *
 * Deliberately narrow. The console library behind this one can draw progress
 * bars, nested sections, definition lists and a dozen table styles, and none of
 * that is here, because the seam's job is to keep the framework's commands off
 * a third-party base class — not to reproduce it. A command that genuinely
 * needs more than these ten is the second customer this contract would widen
 * for; until one exists, the narrow version is the one that can be faked in
 * twenty lines and implemented against a different library in an afternoon.
 */
interface OutputInterface
{
    /** A line, unadorned. */
    public function write(string $line): void;

    /** A finished thing, in the affirmative. */
    public function success(string $message): void;

    /** Something went wrong. Goes to stderr where the implementation can. */
    public function error(string $message): void;

    /** Something is off but the command carried on. */
    public function warning(string $message): void;

    /** An aside: what to do next, what was skipped, why. */
    public function note(string $message): void;

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void;

    /** @param list<string> $items */
    public function listing(array $items): void;

    /**
     * Ask for a line of input, offering $default when one is given.
     *
     * $validator is handed what was typed and returns it, normalised, or throws
     * to reject it. At a terminal a rejection re-prompts with the message, so
     * the caller writes the rule once instead of a loop; where there is nobody
     * to re-prompt, the throw stands and the command fails.
     *
     * @param (callable(string): string)|null $validator
     */
    public function ask(string $question, ?string $default = null, ?callable $validator = null): string;

    /**
     * Ask without echoing what is typed. For passwords, and nothing else.
     *
     * @param (callable(string): string)|null $validator
     */
    public function askHidden(string $question, ?callable $validator = null): string;

    /**
     * Ask a yes/no question. An implementation with no one to ask — a log, a
     * test, a cron run — must answer $default rather than block, which is why
     * every caller passes the safe answer as the default rather than the
     * convenient one.
     */
    public function confirm(string $question, bool $default = true): bool;
}
