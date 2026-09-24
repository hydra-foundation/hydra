<?php

declare(strict_types=1);

namespace Hydra\Auth;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Time-based one-time passwords, RFC 6238: six digits from HMAC-SHA1 over the
 * count of 30-second steps since the epoch. SHA-1 and six digits because that
 * is what every authenticator app reads from a QR code without asking.
 *
 * verify() returns the step a code matched, not a yes. The caller keeps it and
 * passes it back as $after, which is the only thing that stops a code read
 * over a shoulder working again for the rest of its window.
 */
final readonly class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /** Steps either side of now still accepted: a phone's clock drifts, and typing takes time. */
    public const DRIFT = 1;

    /** 160 bits, the HMAC-SHA1 block RFC 4226 recommends. */
    private const SECRET_BYTES = 20;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private ClockInterface $clock) {}

    /** A fresh secret in base32, the form an authenticator app is given. */
    public function secret(): string
    {
        return self::base32(random_bytes(self::SECRET_BYTES));
    }

    /** What the QR code holds: "otpauth://totp/Issuer:account?secret=…&issuer=…". */
    public function uri(string $secret, string $account, string $issuer): string
    {
        self::decode($secret);

        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?' . $query;
    }

    /** The code for a step, now's by default. */
    public function code(string $secret, ?int $step = null): string
    {
        $step ??= $this->step();
        $hmac = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);

        // RFC 4226 dynamic truncation: the low nibble of the last byte picks
        // where four bytes are read, and the top bit is dropped.
        $offset = ord($hmac[19]) & 0x0F;
        $value = unpack('N', substr($hmac, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The step $code matched within the drift, or null. A step at or before
     * $after is not tried: pass the step the last accepted code returned.
     */
    public function verify(string $secret, string $code, ?int $after = null): ?int
    {
        $code = str_replace([' ', '-'], '', $code);
        $now = $this->step();
        $matched = null;

        // Every step is compared, matched or not, so the time taken says
        // nothing about which one it was.
        for ($step = $now - self::DRIFT; $step <= $now + self::DRIFT; $step++) {
            if (hash_equals($this->code($secret, $step), $code) && ($after === null || $step > $after)) {
                $matched ??= $step;
            }
        }

        return $matched;
    }

    private function step(): int
    {
        return intdiv($this->clock->now()->getTimestamp(), self::PERIOD);
    }

    private static function base32(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /**
     * Base32 as an app or a person might write it back: any case, spaced, with
     * or without padding. Anything else is refused rather than read as a
     * different key.
     */
    private static function decode(string $secret): string
    {
        $secret = rtrim(strtoupper(str_replace([' ', '-'], '', $secret)), '=');

        if ($secret === '' || strspn($secret, self::ALPHABET) !== strlen($secret)) {
            throw new InvalidArgumentException('A TOTP secret must be base32: A-Z and 2-7.');
        }

        $bits = '';

        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        if (strlen($bytes) < 10) {
            throw new InvalidArgumentException('A TOTP secret must be at least 80 bits (16 base32 characters).');
        }

        return $bytes;
    }
}
