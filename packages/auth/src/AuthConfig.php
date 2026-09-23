<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Core\Environment;
use InvalidArgumentException;

/**
 * The bcrypt work factor and the signed link lifetimes, resolved from the
 * environment once and passed as a value. Refused at construction when out of
 * range, since a cost password_hash rejects yields a non-hash that fails every
 * later verify.
 */
final readonly class AuthConfig
{
    /** bcrypt's valid work-factor range; password_hash returns false outside it. */
    private const MIN_COST = 4;
    private const MAX_COST = 31;

    /**
     * @param int $resetTtl seconds a password reset link stays valid
     * @param int $verifyTtl seconds an email verification link stays valid
     */
    public function __construct(
        public int $hashCost = 12,
        public int $resetTtl = 3600,
        public int $verifyTtl = 86400,
    ) {
        // A cost outside bcrypt's range makes password_hash emit a warning and
        // return false, a non-hash that would later fail every verify(). Fail
        // loud at construction instead, the same discipline as SessionConfig's
        // sameSite check and the validation package's Pattern rule.
        if ($hashCost < self::MIN_COST || $hashCost > self::MAX_COST) {
            throw new InvalidArgumentException(sprintf(
                'Auth hashCost must be between %d and %d; got %d.',
                self::MIN_COST,
                self::MAX_COST,
                $hashCost,
            ));
        }

        foreach (['resetTtl' => $resetTtl, 'verifyTtl' => $verifyTtl] as $name => $ttl) {
            if ($ttl < 1) {
                throw new InvalidArgumentException("Auth {$name} must be at least 1 second; got {$ttl}.");
            }
        }
    }

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            hashCost: $env->int('AUTH_HASH_COST', 12),
            resetTtl: $env->int('AUTH_RESET_TTL', 3600),
            verifyTtl: $env->int('AUTH_VERIFY_TTL', 86400),
        );
    }
}
