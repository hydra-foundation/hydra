<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Core\Security\Signer;
use Psr\Clock\ClockInterface;

/**
 * A stateless, expiring, URL-safe token naming a user, bound to one value of
 * theirs. Changing that value spends every token bound to it, which is the
 * only revocation there is: nothing is stored.
 *
 * The message is signed, not encrypted, so the bound value travels as a
 * digest. A password hash in the clear would hand a mailbox reader something
 * to crack offline.
 */
final readonly class SignedToken
{
    public function __construct(
        private Signer $signer,
        private ClockInterface $clock,
    ) {}

    /** @param string $purpose keeps a token minted for one flow from opening another */
    public function mint(string $purpose, int $ttl, int|string $id, string $boundTo): string
    {
        $expires = $this->clock->now()->getTimestamp() + $ttl;

        // The id goes last so a string identifier containing the separator
        // cannot shift the fields in front of it.
        $message = implode('|', [$purpose, $expires, self::digest($boundTo), $id]);

        return rtrim(strtr(base64_encode($this->signer->sign($message)), '+/', '-_'), '=');
    }

    /** The claims in $token, or null when it is malformed, forged, expired or for another purpose. */
    public function open(string $purpose, string $token): ?TokenClaims
    {
        $signed = base64_decode(strtr($token, '-_', '+/'), true);
        $message = $signed === false ? null : $this->signer->verify($signed);
        $fields = $message === null ? [] : explode('|', $message, 4);

        if (count($fields) !== 4) {
            return null;
        }

        [$tokenPurpose, $expires, $digest, $id] = $fields;

        if ($tokenPurpose !== $purpose || (int) $expires < $this->clock->now()->getTimestamp()) {
            return null;
        }

        // Only an id that survives the round trip was an int going in.
        return new TokenClaims((string) (int) $id === $id ? (int) $id : $id, $digest);
    }

    public static function digest(string $value): string
    {
        return hash('sha256', $value);
    }
}
