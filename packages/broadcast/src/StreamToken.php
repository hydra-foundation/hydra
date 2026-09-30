<?php

declare(strict_types=1);

namespace Hydra\Broadcast;

use Hydra\Core\Security\Signer;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * A listen token: a user, the topics they may hear, and an expiry, signed so
 * the hub can trust it without a session or a database.
 *
 * Nothing is stored and nothing revokes it, so the expiry is the revocation:
 * a sign-out reaches an open stream when its token runs out.
 *
 * The signed message is `stream|{expires}|{topics}|{id}`. The purpose keeps a
 * token signed for another flow from opening this one. Topics cannot contain
 * a comma or a pipe, and the id goes last, so an id containing a pipe cannot
 * shift the fields in front of it.
 */
final readonly class StreamToken
{
    public const MAX_TOPICS = 32;

    private const PURPOSE = 'stream';

    public function __construct(
        private Signer $signer,
        private ClockInterface $clock,
    ) {}

    /**
     * @param list<string> $topics valid topics; duplicates are granted once
     * @throws InvalidArgumentException for no topics, a bad topic, too many, or a TTL under a second
     */
    public function mint(int|string $userId, array $topics, int $ttl): string
    {
        $topics = array_values(array_unique($topics));

        if ($topics === []) {
            throw new InvalidArgumentException('A listen token needs at least one topic.');
        }

        if (count($topics) > self::MAX_TOPICS) {
            throw new InvalidArgumentException(sprintf(
                'A listen token grants at most %d topics; got %d.',
                self::MAX_TOPICS,
                count($topics),
            ));
        }

        foreach ($topics as $topic) {
            Topic::assertValid($topic);
        }

        if ($ttl < 1) {
            throw new InvalidArgumentException("A listen token must last at least 1 second; got {$ttl}.");
        }

        $expires = $this->clock->now()->getTimestamp() + $ttl;
        $message = implode('|', [self::PURPOSE, $expires, implode(',', $topics), $userId]);

        return rtrim(strtr(base64_encode($this->signer->sign($message)), '+/', '-_'), '=');
    }

    /** Whether $token is valid, expired, or not a listen token at all. */
    public function inspect(string $token): TokenState
    {
        $grant = $this->read($token);

        if ($grant === null) {
            return TokenState::Invalid;
        }

        return $grant->expiresAt < $this->clock->now()->getTimestamp() ? TokenState::Expired : TokenState::Valid;
    }

    /** The grant in $token, or null when it is malformed, forged or expired. */
    public function open(string $token): ?StreamGrant
    {
        $grant = $this->read($token);

        return $grant !== null && $grant->expiresAt >= $this->clock->now()->getTimestamp() ? $grant : null;
    }

    /** The signed grant, whatever its expiry, or null when it is not one. */
    private function read(string $token): ?StreamGrant
    {
        $signed = base64_decode(strtr($token, '-_', '+/'), true);
        $message = $signed === false ? null : $this->signer->verify($signed);
        $fields = $message === null ? [] : explode('|', $message, 4);

        if (count($fields) !== 4) {
            return null;
        }

        [$purpose, $expires, $topics, $id] = $fields;
        $topics = explode(',', $topics);

        if ($purpose !== self::PURPOSE || !ctype_digit($expires) || $id === '') {
            return null;
        }

        foreach ($topics as $topic) {
            if (!Topic::isValid($topic)) {
                return null;
            }
        }

        // Only an id that survives the round trip was an int going in.
        return new StreamGrant((string) (int) $id === $id ? (int) $id : $id, $topics, (int) $expires);
    }
}
