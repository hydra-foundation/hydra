<?php

declare(strict_types=1);

namespace Hydra\Auth\Contracts;

use DateTimeImmutable;
use Hydra\Auth\ApiToken;

/**
 * Where personal API tokens live. The application's to implement, as the user
 * provider is. It only ever sees a token's sha256, never the token itself.
 */
interface ApiTokenStoreInterface
{
    public function create(
        AuthenticatableInterface $user,
        string $name,
        string $hash,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt,
    ): ApiToken;

    public function findByHash(string $hash): ?ApiToken;

    /** @return list<ApiToken> newest first */
    public function forUser(AuthenticatableInterface $user): array;

    public function touch(int|string $id, DateTimeImmutable $at): void;

    /** Remove the token, and say so, only if it belongs to $user. */
    public function revoke(AuthenticatableInterface $user, int|string $id): bool;

    /** @return int how many tokens were removed */
    public function revokeAll(AuthenticatableInterface $user): int;
}
