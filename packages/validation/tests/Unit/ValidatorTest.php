<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Result;
use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\MinLength;
use Hydra\Validation\Rules\Pattern;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator;
    }

    public function testPassesWhenEveryRulePasses(): void
    {
        $result = $this->validator->validate(
            ['name' => 'Ada'],
            ['name' => [new Required, new MaxLength(50)]],
        );

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->passes());
        $this->assertFalse($result->fails());
        $this->assertSame([], $result->errors());
        $this->assertNull($result->first('name'));
    }

    public function testCollectsOneErrorPerField(): void
    {
        $result = $this->validator->validate(
            ['name' => '', 'body' => str_repeat('x', 300)],
            [
                'name' => [new Required('Name is required.')],
                'body' => [new MaxLength(280, 'Too long.')],
            ],
        );

        $this->assertTrue($result->fails());
        $this->assertSame(
            ['name' => 'Name is required.', 'body' => 'Too long.'],
            $result->errors(),
        );
        $this->assertSame('Name is required.', $result->first('name'));
    }

    public function testShortCircuitsToFirstFailingRulePerField(): void
    {
        // Required fails first, so MaxLength must never run.
        $exploding = new class implements RuleInterface {
            public function validate(mixed $value): ?string
            {
                throw new \RuntimeException('second rule should not run');
            }
        };

        $result = $this->validator->validate(
            ['name' => ''],
            ['name' => [new Required('required'), $exploding]],
        );

        $this->assertSame('required', $result->first('name'));
    }

    public function testAbsentFieldIsPassedToRulesAsNull(): void
    {
        // 'name' is not present in the data at all.
        $result = $this->validator->validate(
            [],
            ['name' => [new Required('required')]],
        );

        $this->assertSame('required', $result->first('name'));
    }

    public function testFieldWithNoRulesIsIgnored(): void
    {
        $result = $this->validator->validate(['name' => ''], ['name' => []]);

        $this->assertTrue($result->passes());
    }

    public function testValidatedReturnsExactlyTheRuledSubsetOnPass(): void
    {
        $result = $this->validator->validate(
            ['name' => 'Ada', 'email' => 'ada@example.com', 'admin' => '1'],
            [
                'name'  => [new Required, new MaxLength(50)],
                'email' => [new Required, new Pattern('/\A.+@.+\z/')],
                // 'admin' has no rules on purpose: it must never come out.
            ],
        );

        $this->assertTrue($result->passes());
        $this->assertSame(
            ['name' => 'Ada', 'email' => 'ada@example.com'],
            $result->validated(),
        );
    }

    public function testValidatedExcludesUnruledInputKeys(): void
    {
        // A hostile client posts extra fields; validated() must not smuggle
        // them through to the controller.
        $result = $this->validator->validate(
            ['name' => 'Ada', 'role' => 'admin', 'id' => '1'],
            ['name' => [new Required]],
        );

        $this->assertSame(['name' => 'Ada'], $result->validated());
    }

    public function testValidatedOmitsRuledFieldsAbsentFromInput(): void
    {
        // 'nickname' is optional (no Required) and was not submitted: it must
        // be absent from validated(), not invented as null.
        $result = $this->validator->validate(
            ['name' => 'Ada'],
            [
                'name'     => [new Required],
                'nickname' => [new MaxLength(20)],
            ],
        );

        $this->assertTrue($result->passes());
        $this->assertSame(['name' => 'Ada'], $result->validated());
        $this->assertArrayNotHasKey('nickname', $result->validated());
    }

    public function testValidatedKeepsFalsyButPresentValues(): void
    {
        // Present-but-falsy input ('' with no Required, '0') is still input
        // that passed its rules: presence is array_key_exists, not truthiness.
        $result = $this->validator->validate(
            ['bio' => '', 'count' => '0'],
            [
                'bio'   => [new MaxLength(100)],
                'count' => [new Pattern('/\A\d+\z/')],
            ],
        );

        $this->assertSame(['bio' => '', 'count' => '0'], $result->validated());
    }

    public function testValidatedIncludesFieldDeclaredWithEmptyRuleList(): void
    {
        // Listing a field with an empty rule list is still an explicit opt-in.
        $result = $this->validator->validate(['name' => 'Ada'], ['name' => []]);

        $this->assertSame(['name' => 'Ada'], $result->validated());
    }

    public function testValidatedThrowsOnFailedResult(): void
    {
        $result = $this->validator->validate(
            ['name' => 'Ada', 'email' => 'not-an-email'],
            [
                'name'  => [new Required],
                'email' => [new Pattern('/\A.+@.+\z/')],
            ],
        );

        $this->assertTrue($result->fails());

        // Even though 'name' passed, a failed result hands out no partial data.
        $this->expectException(\LogicException::class);
        $result->validated();
    }

    public function testArrayInputProducesValidationFailureNotException(): void
    {
        // Regression guard (2026-07-06): `field[]=x` arrives as an array. Run
        // through the whole Validator flow, wrong-shaped input must surface as
        // a normal validation failure — never a cast warning (failOnWarning in
        // phpunit.xml pins that) or a thrown exception. The literal "Array"
        // must not slip past the length rules.
        $result = $this->validator->validate(
            ['name' => ['x'], 'email' => ['a@b']],
            [
                'name'  => [new Required, new MinLength(3), new MaxLength(50)],
                'email' => [new Required, new Pattern('/\A.+@.+\z/')],
            ],
        );

        $this->assertTrue($result->fails());
        $this->assertSame('Must be at least 3 characters.', $result->first('name'));
        $this->assertSame('This value is not in the expected format.', $result->first('email'));
    }

    public function testObjectInputProducesValidationFailureNotFatal(): void
    {
        // A plain object would be a fatal TypeError under a (string) cast. It is
        // wrong-shaped client input, so the flow reports a failure, not a fatal.
        $result = $this->validator->validate(
            ['name' => new \stdClass],
            ['name' => [new MinLength(3), new Pattern('/\A.+\z/')]],
        );

        $this->assertTrue($result->fails());
        // Short-circuit: MinLength fails first, so its message wins.
        $this->assertSame('Must be at least 3 characters.', $result->first('name'));
    }
}
