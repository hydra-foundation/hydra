<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\Testing\FakeOutput;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The recording and the queued answers, which the contract case does not reach.
 */
#[CoversClass(FakeOutput::class)]
final class FakeOutputTest extends TestCase
{
    public function test_queued_answers_are_given_in_order_ahead_of_any_default(): void
    {
        $output = (new FakeOutput)->willAnswer(['ada'])->willAnswer(['secret']);

        $this->assertSame('ada', $output->ask('Name?', 'nobody'));
        $this->assertSame('secret', $output->askHidden('Password?'));
        $this->assertSame(['Name?', 'Password?'], $output->questions());
    }

    public function test_a_queued_answer_goes_through_the_validator(): void
    {
        $output = (new FakeOutput)->willAnswer([' ADA ', ' PW ']);
        $normalise = static fn (string $answer): string => strtolower(trim($answer));

        $this->assertSame('ada', $output->ask('Name?', validator: $normalise));
        $this->assertSame('pw', $output->askHidden('Password?', $normalise));
    }

    public function test_ask_with_nothing_queued_and_no_default_names_the_question(): void
    {
        // A question the test did not anticipate is a branch it is not covering.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The command asked "Name?" and nothing was queued to answer it.');
        (new FakeOutput)->ask('Name?');
    }

    public function test_ask_hidden_never_falls_back_to_anything(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The command asked "Password?" and nothing was queued to answer it.');
        (new FakeOutput)->askHidden('Password?');
    }

    public function test_queued_confirmations_are_given_in_order_then_the_default(): void
    {
        $output = (new FakeOutput)->willConfirm([false])->willConfirm([true]);

        $this->assertFalse($output->confirm('One?'));
        $this->assertTrue($output->confirm('Two?', false));
        $this->assertFalse($output->confirm('Three?', false));
    }

    public function test_lines_keep_their_kind(): void
    {
        $output = new FakeOutput;
        $output->write('plain');
        $output->note('aside');
        $output->listing(['a', 'b']);
        $output->table(['H'], [['x', 'y']]);

        $this->assertSame(['plain', 'aside', 'a', 'b', 'x y'], $output->lines());
        $this->assertSame(['aside'], $output->linesOfKind('note'));
        $this->assertSame(['a', 'b'], $output->linesOfKind('listing'));
        $this->assertSame([['headers' => ['H'], 'rows' => [['x', 'y']]]], $output->tables());
    }

    public function test_assert_said_lists_what_was_said_when_it_fails(): void
    {
        $output = new FakeOutput;
        $output->write('Migrated 3.');
        $output->assertSaid('Migrated');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Nothing said contained \"Rolled back\". Said:\n  Migrated 3.");
        $output->assertSaid('Rolled back');
    }

    public function test_assert_did_not_say(): void
    {
        $output = new FakeOutput;
        $output->write('Migrated 3.');
        $output->assertDidNotSay('Rolled back');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Something said contained "Migrated".');
        $output->assertDidNotSay('Migrated');
    }

    public function test_assert_kind_with_no_text_asks_only_whether_any_was_written(): void
    {
        $output = new FakeOutput;
        $output->success('Done.');
        $output->error('Broke.');
        $output->warning('Careful.');
        $output->assertSuccess();
        $output->assertError('Broke');
        $output->assertWarning('Careful');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Nothing was written as error.');
        (new FakeOutput)->assertError();
    }

    public function test_assert_kind_lists_that_kind_when_the_text_is_missing(): void
    {
        $output = new FakeOutput;
        $output->success('Created posts.');
        $output->write('Not a success.');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("No success contained \"Deleted\". Success written:\n  Created posts.");
        $output->assertSuccess('Deleted');
    }

    public function test_assert_nothing_said(): void
    {
        (new FakeOutput)->assertNothingSaid();

        $output = new FakeOutput;
        $output->write('something');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The command said something.');
        $output->assertNothingSaid();
    }
}
