<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\ClientIpResolver;
use Hydra\Http\TrustedProxies;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Identity is the foundation every per-client budget stands on, so the tests
 * that matter most here are the ones where a caller tries to choose its own.
 */
#[CoversClass(ClientIpResolver::class)]
final class ClientIpResolverTest extends TestCase
{
    public function test_with_no_proxy_declared_the_socket_peer_is_the_client(): void
    {
        $resolver = new ClientIpResolver(TrustedProxies::none());

        $this->assertSame('198.51.100.7', $resolver->resolve($this->request('198.51.100.7')));
    }

    public function test_with_no_proxy_declared_a_forwarding_header_is_ignored(): void
    {
        // The header is free for anyone to send. Without a proxy to vouch for
        // it, reading it would let the caller rename itself at will.
        $resolver = new ClientIpResolver(TrustedProxies::none());
        $request = $this->request('198.51.100.7', '203.0.113.1');

        $this->assertSame('198.51.100.7', $resolver->resolve($request));
    }

    public function test_a_header_from_an_untrusted_peer_is_ignored(): void
    {
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.0/8']));
        $request = $this->request('198.51.100.7', '203.0.113.1');

        $this->assertSame('198.51.100.7', $resolver->resolve($request));
    }

    public function test_behind_a_trusted_proxy_the_forwarded_client_is_used(): void
    {
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.1']));
        $request = $this->request('10.0.0.1', '198.51.100.7');

        $this->assertSame('198.51.100.7', $resolver->resolve($request));
    }

    public function test_a_caller_cannot_choose_its_own_identity_by_prepending_hops(): void
    {
        // The whole reason this class exists. The caller sends a forged header;
        // our proxy appends what it actually saw. Reading the list from the left
        // returns the forgery, which is a per-request identity reset for anything
        // counting by client, so the walk starts from the right.
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.1']));
        $request = $this->request('10.0.0.1', '1.2.3.4, 198.51.100.7');

        $this->assertSame('198.51.100.7', $resolver->resolve($request));
    }

    public function test_a_chain_of_trusted_hops_is_walked_past_to_the_real_client(): void
    {
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.0/8']));
        $request = $this->request('10.0.0.1', '198.51.100.7, 10.9.9.9, 10.0.0.2');

        $this->assertSame('198.51.100.7', $resolver->resolve($request));
    }

    public function test_a_forged_hop_buried_behind_trusted_ones_is_still_not_believed_past(): void
    {
        // Everything left of the first untrusted hop is unverifiable, so the
        // walk stops there rather than continuing to the attacker's entry.
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.0/8']));
        $request = $this->request('10.0.0.1', '1.2.3.4, 198.51.100.7, 10.0.0.2');

        $this->assertSame('198.51.100.7', $resolver->resolve($request));
    }

    public function test_a_hop_that_is_not_an_address_is_never_handed_back(): void
    {
        // The nearest hop is whatever the caller typed. Returning it puts
        // arbitrary text wherever the client is recorded, and `activity.ip` is
        // a VARCHAR(45) whose insert an oversized one fails, taking the
        // request's own audit row with it.
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.0/8']));
        $request = $this->request('10.0.0.1', str_repeat('a', 200));

        $this->assertSame('10.0.0.1', $resolver->resolve($request));
    }

    public function test_the_walk_stops_at_a_hop_it_cannot_read(): void
    {
        // Nothing to the left of an unreadable hop can be vouched for, so the
        // peer stands rather than the next address along.
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.0/8']));
        $request = $this->request('10.0.0.1', '198.51.100.7, unknown, 10.0.0.2');

        $this->assertSame('10.0.0.1', $resolver->resolve($request));
    }

    public function test_when_every_hop_is_ours_the_peer_stands(): void
    {
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.0/8']));
        $request = $this->request('10.0.0.1', '10.0.0.5, 10.0.0.2');

        $this->assertSame('10.0.0.1', $resolver->resolve($request));
    }

    public function test_a_port_on_a_forwarded_hop_is_not_part_of_the_address(): void
    {
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.1']));
        $request = $this->request('10.0.0.1', '198.51.100.7:41234');

        $this->assertSame('198.51.100.7', $resolver->resolve($request));
    }

    public function test_a_bracketed_ipv6_hop_is_unwrapped(): void
    {
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.1']));
        $request = $this->request('10.0.0.1', '[2001:db8::1]:41234');

        $this->assertSame('2001:db8::1', $resolver->resolve($request));
    }

    public function test_a_bare_ipv6_hop_keeps_its_colons(): void
    {
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.1']));
        $request = $this->request('10.0.0.1', '2001:db8::1');

        $this->assertSame('2001:db8::1', $resolver->resolve($request));
    }

    public function test_an_empty_header_from_a_trusted_proxy_falls_back_to_the_peer(): void
    {
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.1']));

        $this->assertSame('10.0.0.1', $resolver->resolve($this->request('10.0.0.1')));
    }

    public function test_a_request_with_no_peer_resolves_to_nothing_rather_than_a_guess(): void
    {
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.1']));
        $request = new ServerRequest('GET', 'http://hydra.test/');

        $this->assertNull($resolver->resolve($request));
    }

    public function test_a_declared_list_decides_whose_forwarding_headers_count(): void
    {
        $resolver = new ClientIpResolver(new TrustedProxies(['10.0.0.0/8']));

        $this->assertTrue($resolver->acceptsForwardingFrom($this->request('10.0.0.1')));
        $this->assertFalse($resolver->acceptsForwardingFrom($this->request('198.51.100.7')));
    }

    public function test_with_no_list_declared_forwarding_is_left_to_the_callers_own_opt_in(): void
    {
        // Unlike resolve(), which refuses the header outright: a front proxy
        // that rewrites REMOTE_ADDR leaves nothing here to check a peer against,
        // and vetoing on that absence would break every such deployment.
        $resolver = new ClientIpResolver(TrustedProxies::none());

        $this->assertTrue($resolver->acceptsForwardingFrom($this->request('198.51.100.7')));
    }

    private function request(string $peer, ?string $forwardedFor = null): ServerRequestInterface
    {
        $request = new ServerRequest('GET', 'http://hydra.test/', serverParams: ['REMOTE_ADDR' => $peer]);

        return $forwardedFor === null ? $request : $request->withHeader('X-Forwarded-For', $forwardedFor);
    }
}
