<?php

declare(strict_types=1);

namespace Hydra\Mail\Testing;

use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Mail\Message;
use PHPUnit\Framework\Assert;

/**
 * A mailer that records what it was asked to send, for asserting on.
 */
final class FakeMailer implements MailerInterface
{
    /** @var list<Message> */
    private array $sent = [];

    public function send(Message $message): void
    {
        $this->sent[] = $message;
    }

    /**
     * @param (callable(Message): bool)|null $matching
     * @return list<Message>
     */
    public function sent(?callable $matching = null): array
    {
        return $matching === null
            ? $this->sent
            : array_values(array_filter($this->sent, $matching));
    }

    /**
     * At least one sent message matched, or exactly $times did when given.
     *
     * @param (callable(Message): bool)|null $matching
     */
    public function assertSent(?callable $matching = null, ?int $times = null): void
    {
        $count = count($this->sent($matching));

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, 'No matching message was sent.');

            return;
        }

        Assert::assertSame($times, $count, "Expected {$times} matching messages; {$count} were sent.");
    }

    /** @param callable(Message): bool $matching */
    public function assertNotSent(callable $matching): void
    {
        $count = count($this->sent($matching));

        Assert::assertSame(0, $count, "{$count} matching messages were sent.");
    }

    public function assertSentTo(string $email): void
    {
        $this->assertSent(static fn (Message $m): bool => $m->isFor($email));
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, count($this->sent) . ' messages were sent.');
    }
}
