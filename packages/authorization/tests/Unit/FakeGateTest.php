<?php

declare(strict_types=1);

namespace Hydra\Authorization\Tests\Unit;

use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Authorization\Exceptions\AuthorizationException;
use Hydra\Authorization\Testing\FakeGate;
use Hydra\Authorization\Testing\GateContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The fake against the same contract the real gate answers, plus the recording
 * it exists for. A fake that drifted from the contract would let an application
 * test pass against behaviour the shipped gate does not have, which is worse
 * than having no fake at all.
 */
#[CoversClass(FakeGate::class)]
final class FakeGateTest extends GateContractTestCase
{
    protected function gateDeciding(bool $allows): GateInterface
    {
        return $allows ? FakeGate::allowingEverything() : FakeGate::denyingEverything();
    }

    public function test_a_named_ability_overrides_the_default(): void
    {
        $gate = FakeGate::denyingEverything()->allow('posts.update');

        $this->assertTrue($gate->allows('posts.update'));
        $this->assertFalse($gate->allows('posts.delete'));
    }

    public function test_deny_overrides_an_allowing_default(): void
    {
        $gate = FakeGate::allowingEverything()->deny('posts.delete');

        $this->assertFalse($gate->allows('posts.delete'));
        $this->assertTrue($gate->allows('posts.update'));
    }

    public function test_it_records_every_check_in_order(): void
    {
        $gate = FakeGate::allowingEverything();
        $gate->allows('first');
        $gate->denies('second');
        $gate->authorize('third');

        $this->assertSame(['first', 'second', 'third'], array_column($gate->checks(), 'ability'));
    }

    public function test_denies_records_one_check_rather_than_two(): void
    {
        // denies() is implemented over allows(); if it recorded on the way past,
        // every assertChecked(..., times: 1) on a guard clause would read 2.
        $gate = FakeGate::allowingEverything();
        $gate->denies('posts.update');

        $gate->assertChecked('posts.update', times: 1);
    }

    public function test_it_records_the_subject(): void
    {
        $subject = new \stdClass;
        $gate = FakeGate::allowingEverything();
        $gate->allows('posts.update', $subject);

        $gate->assertCheckedWith('posts.update', $subject);
    }

    public function test_a_subject_that_is_merely_equal_is_not_the_one_checked(): void
    {
        $gate = FakeGate::allowingEverything();
        $gate->allows('posts.update', new \stdClass);

        $this->expectException(AssertionFailedError::class);

        $gate->assertCheckedWith('posts.update', new \stdClass);
    }

    public function test_assert_checked_fails_when_it_was_not(): void
    {
        $gate = FakeGate::allowingEverything();

        $this->expectException(AssertionFailedError::class);

        $gate->assertChecked('posts.update');
    }

    public function test_assert_checked_passes_once_the_ability_was_checked(): void
    {
        $gate = FakeGate::allowingEverything();
        $gate->allows('posts.update');

        $gate->assertChecked('posts.update');
    }

    public function test_assert_not_checked_fails_when_it_was(): void
    {
        $gate = FakeGate::allowingEverything();
        $gate->allows('posts.update');

        $this->expectException(AssertionFailedError::class);

        $gate->assertNotChecked('posts.update');
    }

    public function test_assert_nothing_checked_passes_on_a_fresh_gate(): void
    {
        FakeGate::allowingEverything()->assertNothingChecked();

        $this->expectException(AssertionFailedError::class);

        $gate = FakeGate::allowingEverything();
        $gate->allows('posts.update');
        $gate->assertNothingChecked();
    }

    public function test_authorize_throws_for_a_denied_ability_by_name(): void
    {
        $gate = FakeGate::allowingEverything()->deny('posts.delete');

        $this->expectException(AuthorizationException::class);

        $gate->authorize('posts.delete');
    }

    public function test_a_denied_authorize_is_still_recorded(): void
    {
        // The check happened even though it threw, and a test asserting the
        // controller asked before refusing needs to see it.
        $gate = FakeGate::denyingEverything();

        try {
            $gate->authorize('posts.delete');
        } catch (AuthorizationException) {
            // expected
        }

        $gate->assertChecked('posts.delete', times: 1);
    }
}
