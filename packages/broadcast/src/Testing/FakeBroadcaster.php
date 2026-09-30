<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Testing;

use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Envelope;
use PHPUnit\Framework\Assert;

/**
 * A broadcaster that records what it was asked to publish, for asserting on.
 * It validates like the real drivers, so a test cannot pass on a topic that
 * Redis would be sent and the hub would never grant.
 */
final class FakeBroadcaster implements BroadcasterInterface
{
    /** @var list<Envelope> */
    private array $published = [];

    public function publish(string $topic, string $event, array $data = []): void
    {
        $envelope = new Envelope($topic, $event, $data, 0);
        $envelope->toJson();

        $this->published[] = $envelope;
    }

    /**
     * @param (callable(Envelope): bool)|null $matching
     * @return list<Envelope>
     */
    public function published(?callable $matching = null): array
    {
        return $matching === null
            ? $this->published
            : array_values(array_filter($this->published, $matching));
    }

    /**
     * At least one publish on $topic matched, or exactly $times did when given.
     *
     * @param string|null $event only publishes of this event count
     * @param (callable(Envelope): bool)|null $matching and only those this accepts
     */
    public function assertPublished(string $topic, ?string $event = null, ?callable $matching = null, ?int $times = null): void
    {
        $count = count($this->published(self::filter($topic, $event, $matching)));
        $what = $event === null ? $topic : "{$event} on {$topic}";

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "Nothing matching {$what} was published.");

            return;
        }

        Assert::assertSame($times, $count, "Expected {$times} matching publishes of {$what}; {$count} were published.");
    }

    public function assertNotPublished(string $topic, ?string $event = null): void
    {
        $count = count($this->published(self::filter($topic, $event, null)));
        $what = $event === null ? $topic : "{$event} on {$topic}";

        Assert::assertSame(0, $count, "{$count} publishes of {$what} were made.");
    }

    public function assertNothingPublished(): void
    {
        Assert::assertSame([], $this->published, count($this->published) . ' broadcasts were published.');
    }

    /**
     * @param (callable(Envelope): bool)|null $matching
     * @return callable(Envelope): bool
     */
    private static function filter(string $topic, ?string $event, ?callable $matching): callable
    {
        return static fn (Envelope $e): bool => $e->topic === $topic
            && ($event === null || $e->event === $event)
            && ($matching === null || $matching($e));
    }
}
