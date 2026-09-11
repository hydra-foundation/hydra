<?php

declare(strict_types=1);

namespace Hydra\Http;

use InvalidArgumentException;

/**
 * Trusted proxies
 *
 * The set of addresses whose forwarding headers the application believes.
 *
 * Everything about `X-Forwarded-For` and `X-Forwarded-Proto` hinges on this
 * list: those headers are client-supplied, so they mean nothing until the peer
 * that delivered them is known to overwrite (or append to) them honestly. An
 * empty list is the safe default and says "no proxy in front" — the socket peer
 * is the client, and forwarding headers are ignored entirely.
 */
final readonly class TrustedProxies
{
    /** @var list<array{string, int}> Packed network prefix and its significant bit count. */
    private array $ranges;

    /** @param list<string> $ranges IPv4/IPv6 addresses or CIDR blocks. */
    public function __construct(array $ranges = [])
    {
        $this->ranges = array_map($this->parse(...), array_values($ranges));
    }

    /** No proxy in front: the socket peer is the client, full stop. */
    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    /**
     * Whether $ip belongs to one of the trusted ranges. A malformed or missing
     * address is never trusted — the question is only ever asked about a peer we
     * are deciding to believe, so an unparseable answer must be "no".
     */
    public function contains(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        foreach ($this->ranges as [$prefix, $bits]) {
            // Different address families never match; comparing them would read
            // a 4-byte value against a 16-byte prefix.
            if (strlen($prefix) === strlen($packed) && $this->sharesPrefix($packed, $prefix, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A bare address is its own single-host range, so `10.0.0.1` and
     * `10.0.0.1/32` are the same declaration written two ways.
     *
     * @return array{string, int}
     */
    private function parse(string $range): array
    {
        $range = trim($range);
        [$address, $mask] = array_pad(explode('/', $range, 2), 2, null);

        $packed = @inet_pton((string) $address);

        if ($packed === false) {
            throw new InvalidArgumentException("Trusted proxy \"{$range}\" is not a valid IP address or CIDR block.");
        }

        $width = strlen($packed) * 8;

        if ($mask === null) {
            return [$packed, $width];
        }

        if (preg_match('/^\d+$/', $mask) !== 1 || (int) $mask > $width) {
            throw new InvalidArgumentException(
                "Trusted proxy \"{$range}\" has an invalid prefix length (expected 0-{$width})."
            );
        }

        return [$this->truncate($packed, (int) $mask), (int) $mask];
    }

    /** Zero every bit past the prefix, so `10.1.2.3/8` is stored as `10.0.0.0/8`. */
    private function truncate(string $packed, int $bits): string
    {
        $whole = intdiv($bits, 8);
        $partial = $bits % 8;
        $head = substr($packed, 0, $whole);

        if ($partial !== 0) {
            $head .= chr(ord($packed[$whole]) & (0xFF << (8 - $partial)) & 0xFF);
        }

        return str_pad($head, strlen($packed), "\0");
    }

    private function sharesPrefix(string $packed, string $prefix, int $bits): bool
    {
        $whole = intdiv($bits, 8);
        $partial = $bits % 8;

        if ($whole > 0 && strncmp($packed, $prefix, $whole) !== 0) {
            return false;
        }

        if ($partial === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $partial)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($prefix[$whole]) & $mask);
    }
}
