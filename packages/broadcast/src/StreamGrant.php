<?php

declare(strict_types=1);

namespace Hydra\Broadcast;

/** What an opened listen token grants: whose stream, which topics, until when. */
final readonly class StreamGrant
{
    /**
     * @param list<string> $topics
     * @param int $expiresAt unix seconds; the grant holds through this second
     */
    public function __construct(
        public int|string $userId,
        public array $topics,
        public int $expiresAt,
    ) {}

    public function allows(string $topic): bool
    {
        return in_array($topic, $this->topics, true);
    }
}
