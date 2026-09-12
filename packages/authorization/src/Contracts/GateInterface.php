<?php

declare(strict_types=1);

namespace Hydra\Authorization\Contracts;

/**
 * Decides whether the current user is allowed to do something.
 */
interface GateInterface
{
    public function allows(string $ability, mixed $subject = null): bool;

    public function denies(string $ability, mixed $subject = null): bool;

    /**
     * Returns silently when allowed, throws
     * {@see \Hydra\Authorization\Exceptions\AuthorizationException} (403) when
     * not, so a caller need write no branch at all.
     */
    public function authorize(string $ability, mixed $subject = null): void;
}
