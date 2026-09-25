<?php

declare(strict_types=1);

namespace Hydra\Auth;

/**
 * A token just issued: what was stored, and the one copy of the token itself,
 * to show the user once and then let go of.
 */
final readonly class IssuedApiToken
{
    public function __construct(
        public ApiToken $token,
        #[\SensitiveParameter] public string $plain,
    ) {}

    /** @return array{token: ApiToken, plain: string} */
    public function __debugInfo(): array
    {
        return ['token' => $this->token, 'plain' => '[redacted]'];
    }
}
