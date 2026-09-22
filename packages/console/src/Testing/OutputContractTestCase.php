<?php

declare(strict_types=1);

namespace Hydra\Console\Testing;

use Hydra\Console\Contracts\OutputInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every output owes a command, published so the one a test hands it and
 * the one a terminal hands it answer the same way.
 *
 * The drawing is the implementation's business. What a command relies on is
 * that nothing it says is dropped, and that a question put to nobody, in a
 * test, a pipe or a cron run, comes back with the default instead of blocking
 * or inventing an answer.
 */
abstract class OutputContractTestCase extends TestCase
{
    /** An output with nobody to answer its questions. */
    abstract protected function unattended(): OutputInterface;

    /** Everything $output has shown, as one string. Called once per test. */
    abstract protected function said(OutputInterface $output): string;

    /** @return array<string, array{string}> */
    public static function sayings(): array
    {
        return [
            'write' => ['write'],
            'success' => ['success'],
            'error' => ['error'],
            'warning' => ['warning'],
            'note' => ['note'],
        ];
    }

    #[DataProvider('sayings')]
    public function test_every_way_of_saying_something_shows_the_text(string $method): void
    {
        $output = $this->unattended();

        $output->{$method}("the {$method} text");

        $this->assertStringContainsString("the {$method} text", $this->said($output));
    }

    public function test_a_table_shows_its_headers_and_every_cell(): void
    {
        $output = $this->unattended();

        $output->table(['Name', 'Role'], [['ada', 'admin'], ['grace', 'user']]);

        $said = $this->said($output);
        foreach (['Name', 'Role', 'ada', 'admin', 'grace', 'user'] as $text) {
            $this->assertStringContainsString($text, $said);
        }
    }

    public function test_a_listing_shows_every_item(): void
    {
        $output = $this->unattended();

        $output->listing(['first', 'second']);

        $said = $this->said($output);
        $this->assertStringContainsString('first', $said);
        $this->assertStringContainsString('second', $said);
    }

    public function test_confirm_answers_its_default_when_nobody_is_there(): void
    {
        // Callers pass the safe answer as the default on the strength of this.
        $this->assertFalse($this->unattended()->confirm('Drop every table?', false));
        $this->assertTrue($this->unattended()->confirm('Carry on?', true));
    }

    public function test_ask_answers_its_default_when_nobody_is_there(): void
    {
        $this->assertSame('user', $this->unattended()->ask('Role?', 'user'));
    }

    public function test_the_default_still_goes_through_the_validator(): void
    {
        $answer = $this->unattended()->ask('Role?', ' User ', static fn (string $role): string => strtolower(trim($role)));

        $this->assertSame('user', $answer);
    }

    public function test_a_default_the_validator_rejects_fails_the_command(): void
    {
        // Nobody is there to re-prompt, so the rejection stands. Returning the
        // default anyway would run the command on a value its own rule refused.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No such role.');

        $this->unattended()->ask('Role?', 'root', static fn (string $role): string => in_array($role, ['user', 'admin'], true)
            ? $role
            : throw new InvalidArgumentException('No such role.'));
    }
}
