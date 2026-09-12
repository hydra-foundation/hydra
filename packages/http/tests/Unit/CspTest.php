<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Csp;
use PHPUnit\Framework\TestCase;

/**
 * Compiling directives to a header value, and the builders that add, replace
 * and drop them. Each builder returns a new policy, so the shared default is
 * never mutated by a screen relaxing one directive for itself.
 */
final class CspTest extends TestCase
{
    public function test_compiles_directives_in_declaration_order(): void
    {
        $policy = (new Csp)
            ->with('default-src', "'self'")
            ->with('img-src', "'self'", 'data:');

        $this->assertSame("default-src 'self'; img-src 'self' data:", $policy->compile('abc'));
    }

    public function test_swaps_the_placeholder_for_the_requests_nonce(): void
    {
        $policy = (new Csp)
            ->with('script-src', "'self'", Csp::NONCE);

        $this->assertSame("script-src 'self' 'nonce-abc'", $policy->compile('abc'));
    }

    public function test_emits_a_sourceless_directive_bare(): void
    {
        $policy = (new Csp)->with('upgrade-insecure-requests');

        $this->assertSame('upgrade-insecure-requests', $policy->compile('abc'));
    }

    public function test_with_replaces_the_sources_a_directive_already_had(): void
    {
        $policy = (new Csp)
            ->with('font-src', "'self'")
            ->with('font-src', 'https://fonts.gstatic.com');

        $this->assertSame('font-src https://fonts.gstatic.com', $policy->compile('abc'));
    }

    public function test_allow_keeps_the_sources_a_directive_already_had(): void
    {
        $policy = (new Csp)
            ->with('font-src', "'self'")
            ->allow('font-src', 'https://fonts.gstatic.com');

        $this->assertSame("font-src 'self' https://fonts.gstatic.com", $policy->compile('abc'));
    }

    public function test_allow_does_not_repeat_a_source_the_directive_already_allows(): void
    {
        $policy = (new Csp)
            ->with('font-src', "'self'")
            ->allow('font-src', "'self'");

        $this->assertSame("font-src 'self'", $policy->compile('abc'));
    }

    public function test_allow_creates_a_directive_that_was_not_declared(): void
    {
        $policy = (new Csp)->allow('connect-src', "'self'");

        $this->assertSame("connect-src 'self'", $policy->compile('abc'));
    }

    public function test_without_drops_a_directive(): void
    {
        $policy = (new Csp)
            ->with('default-src', "'self'")
            ->with('frame-ancestors', "'none'")
            ->without('frame-ancestors');

        $this->assertSame("default-src 'self'", $policy->compile('abc'));
    }

    public function test_builders_leave_the_policy_they_were_called_on_alone(): void
    {
        $policy = (new Csp)->with('default-src', "'self'");

        $policy->with('object-src', "'none'");
        $policy->allow('default-src', 'https://cdn.example');
        $policy->without('default-src');

        $this->assertSame("default-src 'self'", $policy->compile('abc'));
    }

    public function test_the_default_policy_confines_the_page_to_its_own_origin(): void
    {
        $compiled = Csp::default()->compile('abc');

        $this->assertSame(
            "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self';"
            . " form-action 'self'; script-src 'self' 'nonce-abc'",
            $compiled,
        );
    }
}
