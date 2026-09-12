<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Authorization\Exceptions\AuthorizationException;

/** A gate whose answer is fixed at construction, so a test can choose the verdict. */
final class AdminsOnlyGate implements GateInterface
{
    public function __construct(private readonly bool $allowed) {}

    public function allows(string $ability, mixed $subject = null): bool
    {
        return $this->allowed;
    }

    public function denies(string $ability, mixed $subject = null): bool
    {
        return !$this->allows($ability, $subject);
    }

    public function authorize(string $ability, mixed $subject = null): void
    {
        if (!$this->allowed) {
            throw new AuthorizationException;
        }
    }
}
