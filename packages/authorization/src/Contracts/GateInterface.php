<?php

declare(strict_types=1);

namespace Hydra\Authorization\Contracts;

/**
 * Decides whether the current user is allowed to do something
 */
interface GateInterface
{
    /**
     * Whether the current user is allowed the given ability against an optional
     * subject
     */
    public function allows(string $ability, mixed $subject = null): bool;

    /**
     * The negation of {@see allows()} — reads cleanly in a guard clause
     */
    public function denies(string $ability, mixed $subject = null): bool;

    /**
     * Enforce the ability: return silently when allowed, otherwise throw an
     * {@see \Hydra\Authorization\Exceptions\AuthorizationException} (HTTP 403).
     * The convenience verb for the common "check, or stop the request" path.
     */
    public function authorize(string $ability, mixed $subject = null): void;
}
