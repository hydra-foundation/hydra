<?php

declare(strict_types=1);

namespace Hydra\Throttle\Tests\Unit;

use Hydra\Throttle\RateLimitPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A policy is built at boot and spent on every request, so the values it
 * refuses are refused once at startup rather than per request.
 */
#[CoversClass(RateLimitPolicy::class)]
final class RateLimitPolicyTest extends TestCase
{
    public function test_the_key_separates_policies_and_clients(): void
    {
        $login = new RateLimitPolicy('login', 5, 300);

        $this->assertNotSame(
            $login->keyFor('198.51.100.7'),
            $login->keyFor('203.0.113.9'),
        );
        $this->assertNotSame(
            $login->keyFor('198.51.100.7'),
            (new RateLimitPolicy('global', 5, 300))->keyFor('198.51.100.7'),
        );
    }

    public function test_a_limit_below_one_is_refused(): void
    {
        // Zero is not "no limit", it is "refuse everyone", and it reads like
        // the former often enough to be worth failing at boot over.
        $this->expectException(InvalidArgumentException::class);

        new RateLimitPolicy('login', 0, 60);
    }

    public function test_a_window_below_one_second_is_refused(): void
    {
        // The store treats a ttl of 0 as "never expires", which would turn a
        // budget into a permanent lockout on the first burst.
        $this->expectException(InvalidArgumentException::class);

        new RateLimitPolicy('login', 5, 0);
    }

    public function test_a_name_that_could_reshape_the_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimitPolicy('login:admin', 5, 60);
    }
}
