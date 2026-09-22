<?php

declare(strict_types=1);

namespace Hydra\Authorization\Testing;

use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Authorization\Exceptions\AuthorizationException;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every gate owes its callers, published so an application that
 * puts its own policy engine behind the seam can be run against it.
 *
 * There is little here, and that is the point: the contract's whole value is
 * that three methods cannot disagree. A caller picks whichever one reads best
 * at the call site — a branch on {@see GateInterface::allows()}, a guard clause
 * on {@see GateInterface::denies()}, or {@see GateInterface::authorize()} with
 * no branch at all — and it must not matter which. An implementation that lets
 * them drift turns the choice of phrasing into a security decision.
 */
abstract class GateContractTestCase extends TestCase
{
    /**
     * A gate that answers $allows for every ability it is asked about,
     * including the name used below.
     */
    abstract protected function gateDeciding(bool $allows): GateInterface;

    /** The ability name handed to the gate. Any name the subclass can answer. */
    protected function ability(): string
    {
        return 'test.ability';
    }

    public function test_allows_is_true_when_the_decision_is_to_allow(): void
    {
        $this->assertTrue($this->gateDeciding(true)->allows($this->ability()));
    }

    public function test_allows_is_false_when_the_decision_is_to_deny(): void
    {
        $this->assertFalse($this->gateDeciding(false)->allows($this->ability()));
    }

    public function test_denies_is_the_negation_of_allows_when_allowing(): void
    {
        $gate = $this->gateDeciding(true);

        $this->assertSame(!$gate->allows($this->ability()), $gate->denies($this->ability()));
    }

    public function test_denies_is_the_negation_of_allows_when_denying(): void
    {
        $gate = $this->gateDeciding(false);

        $this->assertSame(!$gate->allows($this->ability()), $gate->denies($this->ability()));
    }

    public function test_authorize_returns_silently_when_allowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->gateDeciding(true)->authorize($this->ability());
    }

    public function test_authorize_throws_when_denied(): void
    {
        // The 403 is the contract, not merely some exception: the error handler
        // turns it into a response, and a gate that threw something else would
        // be a 500 on a page the visitor is simply not allowed to see.
        $this->expectException(AuthorizationException::class);

        $this->gateDeciding(false)->authorize($this->ability());
    }

    public function test_a_subject_is_optional(): void
    {
        // Half the abilities an application writes are about a row and half are
        // about nothing in particular. A gate that required the second argument
        // would make every caller of the second kind pass a null it does not mean.
        $this->assertTrue($this->gateDeciding(true)->allows($this->ability()));
        $this->assertTrue($this->gateDeciding(true)->allows($this->ability(), null));
    }

    public function test_asking_twice_answers_the_same_way(): void
    {
        // A gate is consulted more than once per request — a menu hides the link
        // and the controller refuses the post — and the two have to agree.
        $gate = $this->gateDeciding(true);

        $this->assertSame($gate->allows($this->ability()), $gate->allows($this->ability()));
    }
}
