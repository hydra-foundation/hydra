<?php

declare(strict_types=1);

namespace Hydra\Authorization;

use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Authorization\Contracts\AbilityInterface;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Authorization\Exceptions\AuthorizationException;
use Hydra\Core\Contracts\ContainerInterface;
use InvalidArgumentException;

/**
 * The shipped {@see GateInterface}: composes an app-supplied ability with the
 * user the auth guard reports for this request.
 */
final class Gate implements GateInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly GuardInterface $guard,
    ) {}

    public function allows(string $ability, mixed $subject = null): bool
    {
        return $this->resolve($ability)->authorize($this->guard->user(), $subject);
    }

    public function denies(string $ability, mixed $subject = null): bool
    {
        return !$this->allows($ability, $subject);
    }

    public function authorize(string $ability, mixed $subject = null): void
    {
        if (!$this->allows($ability, $subject)) {
            throw new AuthorizationException;
        }
    }

    private function resolve(string $ability): AbilityInterface
    {
        $resolved = $this->container->get($ability);

        if (!$resolved instanceof AbilityInterface) {
            throw new InvalidArgumentException(sprintf(
                '%s must implement %s to be used as an ability.',
                $ability,
                AbilityInterface::class,
            ));
        }

        return $resolved;
    }
}
