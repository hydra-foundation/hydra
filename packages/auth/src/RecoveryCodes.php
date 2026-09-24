<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\HasherInterface;

/**
 * Single-use codes that stand in for a lost authenticator. Shown once, stored
 * only as hashes, and spent the first time one works.
 *
 * Where the hashes live is the application's: generate() hands them over and
 * redeem() hands back the ones still unspent, for the caller to write.
 */
final readonly class RecoveryCodes
{
    public const COUNT = 10;

    /** Two groups of five, about 49 bits: guessing is left to the rate limit and the hash. */
    private const LENGTH = 10;

    /** No 0/o, 1/i/l: a code copied off paper has to read back as itself. */
    private const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

    public function __construct(private HasherInterface $hasher) {}

    /**
     * @return array{codes: list<string>, hashes: list<string>} the codes to show, and what to store
     */
    public function generate(int $count = self::COUNT): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $code = '';

            for ($c = 0; $c < self::LENGTH; $c++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        }

        return [
            'codes' => $codes,
            'hashes' => array_map(fn (string $code): string => $this->hasher->hash(self::normalize($code)), $codes),
        ];
    }

    /**
     * The hashes left once $code is spent, or null when it matches none. An
     * empty list is a success: that was the last code.
     *
     * @param list<string> $hashes
     * @return list<string>|null
     */
    public function redeem(string $code, array $hashes): ?array
    {
        $code = self::normalize($code);

        if (strlen($code) !== self::LENGTH) {
            return null;
        }

        foreach ($hashes as $i => $hash) {
            if ($this->hasher->verify($code, $hash)) {
                unset($hashes[$i]);

                return array_values($hashes);
            }
        }

        return null;
    }

    /** Case, spaces and the dash are how it was written down, not part of the code. */
    private static function normalize(string $code): string
    {
        return strtolower(str_replace([' ', '-'], '', $code));
    }
}
