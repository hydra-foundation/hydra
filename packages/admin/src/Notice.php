<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * Notice
 *
 * What a write leaves behind for the next screen to say. A delete can be refused
 * with nowhere to put the message — there is no form to hang it off — so a
 * notice carries whether it is reporting success rather than assuming it.
 */
final readonly class Notice
{
    private function __construct(
        public string $text,
        private bool $failed,
    ) {}

    public static function success(string $text): self
    {
        return new self($text, false);
    }

    public static function failure(string $text): self
    {
        return new self($text, true);
    }

    /**
     * The three a write leaves behind. The controller says what happened rather
     * than what to print, which keeps the admin's own wording in one class —
     * the whole of it, so translating the admin is a question about this file
     * and not a search across the package.
     */
    public static function created(): self
    {
        return self::success('Created');
    }

    public static function saved(): self
    {
        return self::success('Saved');
    }

    public static function deleted(): self
    {
        return self::success('Deleted');
    }

    /** The Bootstrap contextual suffix, and the only styling decision here. */
    public function style(): string
    {
        return $this->failed ? 'danger' : 'success';
    }

    /** Failures are announced; a success is a status update. */
    public function role(): string
    {
        return $this->failed ? 'alert' : 'status';
    }
}
