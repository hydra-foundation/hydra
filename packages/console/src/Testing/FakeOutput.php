<?php

declare(strict_types=1);

namespace Hydra\Console\Testing;

use Hydra\Console\Contracts\OutputInterface;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * An output that records what a command said and answers what it asked.
 *
 * Commands were the least testable part of the framework for exactly one
 * reason: driving one meant driving the console library that ran it. This is
 * the seam that removes that — build the command, hand it an {@see \Hydra\Console\ArrayInput}
 * and one of these, call execute(), and assert on the exit code and the lines.
 *
 * Answers to ask(), askHidden() and confirm() are queued up front. A question
 * with no answer queued throws rather than returning a default, because a
 * command that asked something the test did not anticipate has taken a branch
 * the test is not actually covering.
 */
final class FakeOutput implements OutputInterface
{
    /** @var list<array{kind: string, text: string}> */
    private array $lines = [];

    /** @var list<array{headers: list<string>, rows: list<list<string>>}> */
    private array $tables = [];

    /** @var list<string> */
    private array $answers = [];

    /** @var list<bool> */
    private array $confirmations = [];

    /** @var list<string> */
    private array $questions = [];

    /**
     * Queue the replies to ask() and askHidden(), in the order they are asked.
     *
     * @param list<string> $answers
     */
    public function willAnswer(array $answers): self
    {
        $this->answers = [...$this->answers, ...$answers];

        return $this;
    }

    /**
     * Queue the replies to confirm(), in order.
     *
     * @param list<bool> $confirmations
     */
    public function willConfirm(array $confirmations): self
    {
        $this->confirmations = [...$this->confirmations, ...$confirmations];

        return $this;
    }

    public function write(string $line): void
    {
        $this->lines[] = ['kind' => 'write', 'text' => $line];
    }

    public function success(string $message): void
    {
        $this->lines[] = ['kind' => 'success', 'text' => $message];
    }

    public function error(string $message): void
    {
        $this->lines[] = ['kind' => 'error', 'text' => $message];
    }

    public function warning(string $message): void
    {
        $this->lines[] = ['kind' => 'warning', 'text' => $message];
    }

    public function note(string $message): void
    {
        $this->lines[] = ['kind' => 'note', 'text' => $message];
    }

    public function table(array $headers, array $rows): void
    {
        $this->tables[] = ['headers' => $headers, 'rows' => $rows];

        foreach ($rows as $row) {
            $this->lines[] = ['kind' => 'table', 'text' => implode(' ', $row)];
        }
    }

    public function listing(array $items): void
    {
        foreach ($items as $item) {
            $this->lines[] = ['kind' => 'listing', 'text' => $item];
        }
    }

    public function ask(string $question, ?string $default = null, ?callable $validator = null): string
    {
        $this->questions[] = $question;

        if ($this->answers !== []) {
            return $this->validate(array_shift($this->answers), $validator);
        }

        if ($default !== null) {
            return $this->validate($default, $validator);
        }

        throw new RuntimeException(sprintf('The command asked "%s" and nothing was queued to answer it.', $question));
    }

    public function askHidden(string $question, ?callable $validator = null): string
    {
        $this->questions[] = $question;

        if ($this->answers === []) {
            throw new RuntimeException(sprintf('The command asked "%s" and nothing was queued to answer it.', $question));
        }

        return $this->validate(array_shift($this->answers), $validator);
    }

    /**
     * Apply the caller's validator, once. A terminal would re-prompt on a
     * rejection; there is nobody here to re-prompt, and looping over the same
     * queued answer would not terminate, so the throw stands -- which is also
     * what happens under --no-interaction in CI.
     *
     * @param (callable(string): string)|null $validator
     */
    private function validate(string $answer, ?callable $validator): string
    {
        return $validator === null ? $answer : $validator($answer);
    }

    public function confirm(string $question, bool $default = true): bool
    {
        $this->questions[] = $question;

        return $this->confirmations === [] ? $default : array_shift($this->confirmations);
    }

    /**
     * Everything said, in order, without the distinction of how it was said.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_column($this->lines, 'text');
    }

    /** @return list<string> the messages written as $kind */
    public function linesOfKind(string $kind): array
    {
        return array_values(array_map(
            static fn (array $line): string => $line['text'],
            array_filter($this->lines, static fn (array $line): bool => $line['kind'] === $kind),
        ));
    }

    /** @return list<array{headers: list<string>, rows: list<list<string>>}> */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return list<string> every question asked, in order */
    public function questions(): array
    {
        return $this->questions;
    }

    /** Some line, of any kind, contains this text. */
    public function assertSaid(string $text): void
    {
        Assert::assertTrue(
            $this->contains($this->lines(), $text),
            sprintf("Nothing said contained \"%s\". Said:\n  %s", $text, implode("\n  ", $this->lines())),
        );
    }

    public function assertDidNotSay(string $text): void
    {
        Assert::assertFalse(
            $this->contains($this->lines(), $text),
            sprintf('Something said contained "%s".', $text),
        );
    }

    public function assertSuccess(string $text = ''): void
    {
        $this->assertKind('success', $text);
    }

    public function assertError(string $text = ''): void
    {
        $this->assertKind('error', $text);
    }

    public function assertWarning(string $text = ''): void
    {
        $this->assertKind('warning', $text);
    }

    public function assertNothingSaid(): void
    {
        Assert::assertSame([], $this->lines(), 'The command said something.');
    }

    private function assertKind(string $kind, string $text): void
    {
        $said = $this->linesOfKind($kind);

        if ($text === '') {
            Assert::assertNotSame([], $said, "Nothing was written as {$kind}.");

            return;
        }

        Assert::assertTrue(
            $this->contains($said, $text),
            sprintf("No %s contained \"%s\". %s written:\n  %s", $kind, $text, ucfirst($kind), implode("\n  ", $said)),
        );
    }

    /** @param list<string> $haystack */
    private function contains(array $haystack, string $needle): bool
    {
        foreach ($haystack as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }
}
