<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Client IP resolver
 *
 * Answers "who sent this request?" — the one question anything that counts per
 * client has to get right.
 *
 * `X-Forwarded-For` is a list a proxy *appends* to, so its leftmost entry is
 * whatever the original caller chose to send. Reading that entry hands the
 * caller its own identity: an attacker varies it per request and every per-IP
 * budget in the application resets with it. The list is therefore walked from
 * the right, dropping hops that are trusted proxies, and the first address that
 * is not one of ours is the furthest point we can still vouch for.
 */
final readonly class ClientIpResolver
{
    public function __construct(private TrustedProxies $proxies = new TrustedProxies) {}

    /**
     * The client address, or null when the peer is unknown (a CLI or test
     * request with no REMOTE_ADDR). Callers keying a limit on this must decide
     * what an unidentifiable client means for them, so it is not papered over.
     */
    public function resolve(ServerRequestInterface $request): ?string
    {
        $peer = $this->peer($request);

        // With no proxy declared, the socket peer is the client and forwarding
        // headers are noise — believing them here is the spoof.
        if ($this->proxies->isEmpty() || !$this->proxies->contains($peer)) {
            return $peer;
        }

        foreach ($this->chain($request) as $hop) {
            if (!$this->proxies->contains($hop)) {
                return $hop;
            }
        }

        // Every hop was one of ours, so the peer is the closest thing to a
        // client that exists — a health check from inside the perimeter.
        return $peer;
    }

    /**
     * Whether a forwarding header on this request may be believed at all.
     *
     * With no proxy list declared the application has expressed no opinion, so
     * a caller's own opt-in stands — the bundled nginx resolves `X-Forwarded-For`
     * into REMOTE_ADDR itself, which leaves the proxy invisible here and makes a
     * peer check unanswerable. Once a list exists the peer must be on it, and a
     * request that arrived directly no longer borrows the proxy's word.
     *
     * This deliberately differs from resolve(), which ignores the header
     * outright when no list is declared: an address is something we must not
     * take on trust, whereas the scheme has already been opted into by name.
     */
    public function acceptsForwardingFrom(ServerRequestInterface $request): bool
    {
        return $this->proxies->isEmpty() || $this->proxies->contains($this->peer($request));
    }

    /**
     * The forwarded chain, furthest hop first — the order the walk needs, which
     * is the reverse of how the header reads.
     *
     * @return list<string>
     */
    private function chain(ServerRequestInterface $request): array
    {
        $header = $request->getHeaderLine('X-Forwarded-For');

        if ($header === '') {
            return [];
        }

        $hops = array_values(array_filter(array_map(
            // A port may be appended to an IPv4 hop, and an IPv6 hop may arrive
            // bracketed; neither belongs in an address comparison.
            static fn (string $hop): string => self::normalise($hop),
            explode(',', $header),
        ), static fn (string $hop): bool => $hop !== ''));

        return array_reverse($hops);
    }

    private static function normalise(string $hop): string
    {
        $hop = trim($hop);

        if (str_starts_with($hop, '[')) {
            return substr($hop, 1, (strpos($hop, ']') ?: 1) - 1);
        }

        // Only strip a trailing :port from IPv4 — a bare IPv6 address is all colons.
        if (substr_count($hop, ':') === 1) {
            return substr($hop, 0, (int) strpos($hop, ':'));
        }

        return $hop;
    }

    private function peer(ServerRequestInterface $request): ?string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $remote !== '' ? $remote : null;
    }
}
