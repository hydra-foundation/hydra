<?php

declare(strict_types=1);

namespace Hydra\Authorization\Testing;

use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Authorization\Exceptions\AuthorizationException;
use PHPUnit\Framework\Assert;

/**
 * A gate that decides what it is told to and records what it was asked, for
 * asserting on.
 *
 * The shipped {@see \Hydra\Authorization\Gate} resolves an ability out of the
 * container and reads the current user from the auth guard, so testing a
 * controller's authorization through it means standing up a container, a guard
 * and a user to reach a single boolean. This answers that boolean directly,
 * which leaves a test about a controller about the controller.
 *
 * A decision is per ability name, over a default set at construction. Nothing
 * here looks at the subject: the question a caller is testing is whether the
 * check happened and what it did, and {@see checks()} hands back the subject
 * for the rarer test that cares.
 */
final class FakeGate implements GateInterface
{
    /** @var list<array{ability: string, subject: mixed}> */
    private array $checks = [];

    /** @var array<string, bool> */
    private array $decisions = [];

    /** @param bool $default the answer for any ability not named by allow() or deny() */
    public function __construct(private readonly bool $default = true) {}

    /** Allows everything that is not later denied by name. */
    public static function allowingEverything(): self
    {
        return new self(true);
    }

    /**
     * Denies everything that is not later allowed by name — the safer default
     * when the test is about what a denial does.
     */
    public static function denyingEverything(): self
    {
        return new self(false);
    }

    public function allow(string ...$abilities): self
    {
        foreach ($abilities as $ability) {
            $this->decisions[$ability] = true;
        }

        return $this;
    }

    public function deny(string ...$abilities): self
    {
        foreach ($abilities as $ability) {
            $this->decisions[$ability] = false;
        }

        return $this;
    }

    public function allows(string $ability, mixed $subject = null): bool
    {
        $this->checks[] = ['ability' => $ability, 'subject' => $subject];

        return $this->decisions[$ability] ?? $this->default;
    }

    public function denies(string $ability, mixed $subject = null): bool
    {
        return !$this->allows($ability, $subject);
    }

    public function authorize(string $ability, mixed $subject = null): void
    {
        if (!$this->allows($ability, $subject)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Every check made, oldest first, optionally filtered.
     *
     * @param (callable(string, mixed): bool)|null $matching
     * @return list<array{ability: string, subject: mixed}>
     */
    public function checks(?callable $matching = null): array
    {
        if ($matching === null) {
            return $this->checks;
        }

        return array_values(array_filter(
            $this->checks,
            static fn (array $check): bool => $matching($check['ability'], $check['subject']),
        ));
    }

    /** The ability was checked at least once, or exactly $times when given. */
    public function assertChecked(string $ability, ?int $times = null): void
    {
        $count = count($this->checks(static fn (string $name): bool => $name === $ability));

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "The ability {$ability} was never checked.");

            return;
        }

        Assert::assertSame($times, $count, "Expected {$ability} to be checked {$times} times; it was checked {$count}.");
    }

    public function assertNotChecked(string $ability): void
    {
        $count = count($this->checks(static fn (string $name): bool => $name === $ability));

        Assert::assertSame(0, $count, "The ability {$ability} was checked {$count} times.");
    }

    /**
     * The ability was checked against this subject. The comparison is identity,
     * which is the question worth asking: that the row the controller loaded is
     * the row it authorized, not one equal to it.
     */
    public function assertCheckedWith(string $ability, mixed $subject): void
    {
        $count = count($this->checks(
            static fn (string $name, mixed $seen): bool => $name === $ability && $seen === $subject,
        ));

        Assert::assertGreaterThan(0, $count, "The ability {$ability} was not checked against that subject.");
    }

    public function assertNothingChecked(): void
    {
        Assert::assertSame([], $this->checks, count($this->checks) . ' abilities were checked.');
    }
}
