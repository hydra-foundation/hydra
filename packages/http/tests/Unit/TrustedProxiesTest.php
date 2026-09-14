<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\TrustedProxies;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The list every forwarding decision is measured against. A range that matches
 * too much silently promotes strangers to proxies, so the boundaries matter
 * more here than the happy path.
 */
#[CoversClass(TrustedProxies::class)]
final class TrustedProxiesTest extends TestCase
{
    public function test_nothing_is_trusted_by_default(): void
    {
        $proxies = TrustedProxies::none();

        $this->assertTrue($proxies->isEmpty());
        $this->assertFalse($proxies->contains('10.0.0.1'));
    }

    public function test_a_bare_address_trusts_that_host_and_no_neighbour(): void
    {
        $proxies = new TrustedProxies(['203.0.113.5']);

        $this->assertTrue($proxies->contains('203.0.113.5'));
        $this->assertFalse($proxies->contains('203.0.113.6'));
    }

    public function test_a_cidr_block_trusts_its_range_and_stops_at_the_edge(): void
    {
        $proxies = new TrustedProxies(['172.18.0.0/16']);

        $this->assertTrue($proxies->contains('172.18.0.1'));
        $this->assertTrue($proxies->contains('172.18.255.254'));
        $this->assertFalse($proxies->contains('172.19.0.1'));
        $this->assertFalse($proxies->contains('172.17.255.254'));
    }

    public function test_a_prefix_that_ends_mid_byte_is_masked_not_rounded(): void
    {
        // /12 covers 172.16.0.0 through 172.31.255.255, the private range that
        // Docker allocates from, and the classic place an off-by-one bit shows.
        $proxies = new TrustedProxies(['172.16.0.0/12']);

        $this->assertTrue($proxies->contains('172.16.0.1'));
        $this->assertTrue($proxies->contains('172.31.255.255'));
        $this->assertFalse($proxies->contains('172.32.0.1'));
        $this->assertFalse($proxies->contains('172.15.255.255'));
    }

    public function test_a_block_is_matched_from_its_network_not_the_address_written(): void
    {
        // Declaring 10.1.2.3/8 means the 10.0.0.0/8 network; the host bits are
        // noise and must not narrow the match.
        $proxies = new TrustedProxies(['10.1.2.3/8']);

        $this->assertTrue($proxies->contains('10.255.255.255'));
        $this->assertFalse($proxies->contains('11.0.0.1'));
    }

    public function test_ipv6_ranges_match_independently_of_ipv4(): void
    {
        $proxies = new TrustedProxies(['2001:db8::/32', '10.0.0.0/8']);

        $this->assertTrue($proxies->contains('2001:db8:1234::1'));
        $this->assertFalse($proxies->contains('2001:db9::1'));
        $this->assertTrue($proxies->contains('10.0.0.1'));
    }

    public function test_an_address_that_is_not_an_address_is_never_trusted(): void
    {
        $proxies = new TrustedProxies(['0.0.0.0/0']);

        // Even a range covering the whole internet cannot vouch for a non-address.
        $this->assertTrue($proxies->contains('198.51.100.7'));
        $this->assertFalse($proxies->contains('not-an-ip'));
        $this->assertFalse($proxies->contains(''));
        $this->assertFalse($proxies->contains(null));
    }

    public function test_a_malformed_range_is_refused_at_construction(): void
    {
        // Config that silently trusts nothing is worse than config that refuses
        // to boot: the app looks hardened and is not.
        $this->expectException(InvalidArgumentException::class);

        new TrustedProxies(['10.0.0.0/8', 'nonsense']);
    }

    public function test_a_prefix_wider_than_the_family_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrustedProxies(['10.0.0.0/33']);
    }
}
