<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Core\Environment;
use InvalidArgumentException;

/**
 * The bcrypt work factor, resolved from the environment once and passed as a
 * value. Refused at construction when outside bcrypt's range, since a cost
 * password_hash rejects yields a non-hash that fails every later verify.
 */
final readonly class AuthConfig
{
    /** bcrypt's valid work-factor range; password_hash returns false outside it. */
    private const MIN_COST = 4;
    private const MAX_COST = 31;

    public function __construct(public int $hashCost = 12)
    {
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
    }

    public static function fromEnvironment(Environment $env): self
    {
        return new self(hashCost: $env->int('AUTH_HASH_COST', 12));
    }
}
