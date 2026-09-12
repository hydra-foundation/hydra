<?php

declare(strict_types=1);

namespace Hydra\Http;

/**
 * One random token per request, minted on first use and the same for every
 * reader after it. The page stamps it on the markup it vouches for and the
 * policy header names it; the two only agree while both read one value.
 */
final class CspNonce
{
    private ?string $value = null;

    public function value(): string
    {
        // base64url without padding: legal in the header's base64-value and in
        // an HTML attribute alike, so neither side needs an escaping pass that
        // could put it out of step with the other.
        return $this->value ??= rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
