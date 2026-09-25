<?php

declare(strict_types=1);

namespace Hydra\Auth;

use DateTimeImmutable;

/**
 * A stored personal API token. Carries the hash, never the token: that exists
 * only in the {@see IssuedApiToken} handed back once at creation.
 */
final readonly class ApiToken
{
    public function __construct(
        public int|string $id,
        public int|string $userId,
        public string $name,
        public string $hash,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $lastUsedAt = null,
    ) {}
}
