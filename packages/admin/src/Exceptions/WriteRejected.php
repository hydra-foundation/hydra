<?php

declare(strict_types=1);

namespace Hydra\Admin\Exceptions;

use RuntimeException;

/**
 * A source refusing a write for a reason only it can know — a unique column
 * already taken, a row another process moved. Thrown, not returned, so a source
 * that has nothing to say about a write still has nothing to return.
 */
final class WriteRejected extends RuntimeException
{
    /** @param array<string, string> $errors keyed by input name; an unknown key shows above the form */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The source rejected the write.');
    }

    public static function on(string $field, string $message): self
    {
        return new self([$field => $message]);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The refusal said in one line, for a screen with no form to hang the
     * messages on their inputs — a delete has only a notice to say it in.
     */
    public function summary(): string
    {
        return implode(' ', $this->errors);
    }
}
