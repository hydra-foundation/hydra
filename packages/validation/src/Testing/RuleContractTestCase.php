<?php

declare(strict_types=1);

namespace Hydra\Validation\Testing;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

/**
 * The behaviour every rule owes the validator, published so an application
 * writing one of its own can be run against it.
 *
 * A rule is the one piece of the framework an application is most likely to
 * extend, and the one handed the least trustworthy input: the value reaching
 * {@see RuleInterface::validate()} came from a request body, so its type is
 * whatever a client chose to send. Most of what follows is about that — a rule
 * that assumes a string and is handed an array is a 500 on a form submission,
 * which is both an outage and an information leak.
 *
 * Subclasses name values that must pass and values that must fail. The odd
 * types are checked for every rule regardless, because no rule may fatal on
 * them even when it has nothing sensible to say about them.
 */
abstract class RuleContractTestCase extends TestCase
{
    /** The rule under test, configured however the subclass likes. */
    abstract protected function rule(): RuleInterface;

    /**
     * Values this rule must accept, keyed by a description.
     *
     * @return iterable<string, array{mixed}>
     */
    abstract public static function passingValues(): iterable;

    /**
     * Values this rule must reject, keyed by a description.
     *
     * @return iterable<string, array{mixed}>
     */
    abstract public static function failingValues(): iterable;

    /** The rest of the input, for a rule that reads another field. */
    protected function context(mixed $value = null): Context
    {
        return new Context(['field' => $value], 'field');
    }

    #[DataProvider('passingValues')]
    public function test_a_passing_value_returns_null(mixed $value): void
    {
        // Null, specifically, and not an empty string: the validator reads the
        // return as "is there a message", and '' is a message that renders as
        // an empty error beside the field.
        $this->assertNull($this->rule()->validate($value, $this->context($value)));
    }

    #[DataProvider('failingValues')]
    public function test_a_failing_value_returns_a_message(mixed $value): void
    {
        $message = $this->rule()->validate($value, $this->context($value));

        $this->assertIsString($message);
        $this->assertNotSame('', trim($message), 'A failing rule must say why.');
    }

    #[DataProvider('passingValues')]
    public function test_a_passing_value_answers_the_same_way_twice(mixed $value): void
    {
        // A rule instance is reused across fields and across requests — the
        // wildcard path runs one instance over every element of an array — so a
        // rule that remembers anything about the last value it saw is a rule
        // whose answer depends on validation order.
        $rule = $this->rule();
        $context = $this->context($value);

        $this->assertSame($rule->validate($value, $context), $rule->validate($value, $context));
    }

    /** @return iterable<string, array{mixed}> */
    public static function valuesOfSurprisingType(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'zero' => [0];
        yield 'false' => [false];
        yield 'empty array' => [[]];
        yield 'nested array' => [['a' => ['b' => 'c']]];
        yield 'object' => [new stdClass];
        yield 'float' => [1.5];
    }

    #[DataProvider('valuesOfSurprisingType')]
    public function test_it_survives_a_value_of_any_type(mixed $value): void
    {
        // A client posts field[]=x where the form asked for field=x, and the
        // rule is handed an array. Passing or failing are both acceptable
        // answers; a TypeError is not, because it is a 500 with a stack trace
        // where a 422 with a message belongs.
        try {
            $result = $this->rule()->validate($value, $this->context($value));
        } catch (Throwable $e) {
            $this->fail(sprintf(
                '%s threw %s on a value of type %s: %s',
                $this->rule()::class,
                $e::class,
                get_debug_type($value),
                $e->getMessage(),
            ));
        }

        // Surviving the call is the claim. The message rule still applies if the
        // rule did choose to speak, so assert that rather than the return type
        // the signature already guarantees.
        $this->assertTrue(
            $result === null || trim($result) !== '',
            'A rule that rejected an odd value still has to say why.',
        );
    }
}
