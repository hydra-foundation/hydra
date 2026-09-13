<?php

declare(strict_types=1);

namespace Hydra\Throttle;

use Hydra\Core\Environment;

/**
 * The application-wide budget, read from the environment. The numbers are
 * validated by {@see RateLimitPolicy} when the policy is built, so a limit of
 * zero fails at boot rather than refusing every request in production.
 */
final readonly class ThrottleConfig
{
    public const NAME = 'global';

    public function __construct(
        public bool $enabled = true,
        public int $limit = 120,
        public int $window = 60,
    ) {}

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            // On unless switched off: a limiter that has to be remembered is
            // one that is missing wherever it was forgotten.
            enabled: $env->bool('RATE_LIMIT_ENABLED', true),
            limit: $env->int('RATE_LIMIT', 120),
            window: $env->int('RATE_LIMIT_WINDOW', 60),
        );
    }

    public function policy(): RateLimitPolicy
    {
        return new RateLimitPolicy(self::NAME, $this->limit, $this->window);
    }
}
