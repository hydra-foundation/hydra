<?php

declare(strict_types=1);

namespace Hydra\Auth;

use DateTimeImmutable;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Personal API tokens: issued once in the clear, kept only as a sha256, and
 * accepted while their owner exists and their expiry has not passed. A fast
 * hash is enough here, unlike a password: the token is 256 random bits.
 */
final readonly class ApiTokens
{
    /** Lets a secret scanner, or a person, recognise one that leaked. */
    public const PREFIX = 'hyd_';

    private const BYTES = 32;

    private const MAX_NAME = 100;

    public function __construct(
        private ApiTokenStoreInterface $store,
        private UserProviderInterface $users,
        private ClockInterface $clock,
    ) {}

    public function issue(AuthenticatableInterface $user, string $name, ?DateTimeImmutable $expiresAt = null): IssuedApiToken
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new InvalidArgumentException(sprintf('An API token name must be 1 to %d characters.', self::MAX_NAME));
        }

        $now = $this->clock->now();

        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new InvalidArgumentException('An API token must expire in the future, or never.');
        }

        $plain = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');

        return new IssuedApiToken(
            $this->store->create($user, $name, hash('sha256', $plain), $now, $expiresAt),
            $plain,
        );
    }

    public function authenticate(#[\SensitiveParameter] string $plain): ?AuthenticatedToken
    {
        if (preg_match('/^' . self::PREFIX . '[A-Za-z0-9_-]{43}$/D', $plain) !== 1) {
            return null;
        }

        $token = $this->store->findByHash(hash('sha256', $plain));
        $now = $this->clock->now();

        if ($token === null || ($token->expiresAt !== null && $token->expiresAt <= $now)) {
            return null;
        }

        $user = $this->users->byIdentifier($token->userId);

        if ($user === null) {
            return null;
        }

        $this->store->touch($token->id, $now);

        return new AuthenticatedToken($token, $user);
    }
}
