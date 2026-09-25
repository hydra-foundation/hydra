<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use DateTimeImmutable;
use Hydra\Auth\ApiToken;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * An {@see ApiTokenStoreInterface} in memory, for tests and for an application
 * that has not written its own yet.
 */
final class ArrayApiTokenStore implements ApiTokenStoreInterface
{
    /** @var array<int, ApiToken> */
    private array $tokens = [];

    private int $nextId = 1;

    public function create(
        AuthenticatableInterface $user,
        string $name,
        string $hash,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt,
    ): ApiToken {
        $id = $this->nextId++;

        return $this->tokens[$id] = new ApiToken($id, $user->getAuthIdentifier(), $name, $hash, $createdAt, $expiresAt);
    }

    public function findByHash(string $hash): ?ApiToken
    {
        foreach ($this->tokens as $token) {
            if (hash_equals($token->hash, $hash)) {
                return $token;
            }
        }

        return null;
    }

    public function forUser(AuthenticatableInterface $user): array
    {
        $mine = array_filter($this->tokens, fn (ApiToken $t) => $t->userId === $user->getAuthIdentifier());

        usort($mine, fn (ApiToken $a, ApiToken $b) => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $mine;
    }

    public function touch(int|string $id, DateTimeImmutable $at): void
    {
        $token = $this->tokens[$id] ?? null;

        if ($token !== null) {
            $this->tokens[$id] = new ApiToken(
                $token->id,
                $token->userId,
                $token->name,
                $token->hash,
                $token->createdAt,
                $token->expiresAt,
                $at,
            );
        }
    }

    public function revoke(AuthenticatableInterface $user, int|string $id): bool
    {
        if (($this->tokens[$id] ?? null)?->userId !== $user->getAuthIdentifier()) {
            return false;
        }

        unset($this->tokens[$id]);

        return true;
    }

    public function revokeAll(AuthenticatableInterface $user): int
    {
        $before = count($this->tokens);
        $this->tokens = array_filter($this->tokens, fn (ApiToken $t) => $t->userId !== $user->getAuthIdentifier());

        return $before - count($this->tokens);
    }
}
