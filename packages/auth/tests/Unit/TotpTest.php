<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Totp;
use Hydra\Core\Testing\FrozenClock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Totp::class)]
final class TotpTest extends TestCase
{
    /** RFC 6238 appendix B's SHA-1 seed, "12345678901234567890", in base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private FrozenClock $clock;

    private Totp $totp;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock;
        $this->totp = new Totp($this->clock);
    }

    /**
     * RFC 6238's SHA-1 vectors are eight digits; six is their last six.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function rfcVectors(): iterable
    {
        yield '59' => [59, '287082'];
        yield '1111111109' => [1111111109, '081804'];
        yield '1111111111' => [1111111111, '050471'];
        yield '1234567890' => [1234567890, '005924'];
        yield '2000000000' => [2000000000, '279037'];
        yield '20000000000' => [20000000000, '353130'];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_agrees_with_the_rfc(int $time, string $code): void
    {
        $this->clock->set('@' . $time);

        $this->assertSame($code, $this->totp->code(self::RFC_SECRET));
        $this->assertSame(intdiv($time, 30), $this->totp->verify(self::RFC_SECRET, $code));
    }

    public function test_a_secret_is_160_bits_of_base32_and_never_repeats(): void
    {
        $secret = $this->totp->secret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, $this->totp->secret());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->totp->code($secret));
    }

    public function test_a_generated_secret_round_trips_through_base32(): void
    {
        // A secret whose bits survive the round trip gives the code an app
        // computing from the same base32 would, which RFC_SECRET already pins.
        for ($i = 0; $i < 20; $i++) {
            $secret = $this->totp->secret();
            $code = $this->totp->code($secret);

            $this->assertNotNull($this->totp->verify($secret, $code));
        }
    }

    public function test_one_step_either_side_is_accepted_and_two_is_not(): void
    {
        $this->clock->set('@1111111111');
        $now = intdiv(1111111111, 30);

        $this->assertSame($now - 1, $this->totp->verify(self::RFC_SECRET, $this->totp->code(self::RFC_SECRET, $now - 1)));
        $this->assertSame($now + 1, $this->totp->verify(self::RFC_SECRET, $this->totp->code(self::RFC_SECRET, $now + 1)));
        $this->assertNull($this->totp->verify(self::RFC_SECRET, $this->totp->code(self::RFC_SECRET, $now - 2)));
        $this->assertNull($this->totp->verify(self::RFC_SECRET, $this->totp->code(self::RFC_SECRET, $now + 2)));
    }

    public function test_a_code_is_refused_once_its_step_has_been_used(): void
    {
        $this->clock->set('@1111111111');
        $code = $this->totp->code(self::RFC_SECRET);

        $used = $this->totp->verify(self::RFC_SECRET, $code);

        $this->assertNotNull($used);
        $this->assertNull($this->totp->verify(self::RFC_SECRET, $code, after: $used));
    }

    public function test_an_earlier_step_is_refused_after_a_later_one_was_used(): void
    {
        $this->clock->set('@1111111111');
        $now = intdiv(1111111111, 30);

        $this->assertNull($this->totp->verify(self::RFC_SECRET, $this->totp->code(self::RFC_SECRET, $now - 1), after: $now));
        $this->assertSame($now + 1, $this->totp->verify(self::RFC_SECRET, $this->totp->code(self::RFC_SECRET, $now + 1), after: $now));
    }

    public function test_the_code_may_be_typed_with_a_space_or_a_dash(): void
    {
        $this->clock->set('@59');

        $this->assertNotNull($this->totp->verify(self::RFC_SECRET, '287 082'));
        $this->assertNotNull($this->totp->verify(self::RFC_SECRET, '287-082'));
    }

    /** @return iterable<string, array{string}> */
    public static function notACode(): iterable
    {
        yield 'empty' => [''];
        yield 'five digits' => ['28708'];
        yield 'seven digits' => ['2870820'];
        yield 'the RFC\'s eight' => ['94287082'];
        yield 'letters' => ['28708a'];
        yield 'a trailing newline' => ["287082\n"];
    }

    #[DataProvider('notACode')]
    public function test_what_is_not_six_digits_is_refused(string $code): void
    {
        $this->clock->set('@59');

        $this->assertNull($this->totp->verify(self::RFC_SECRET, $code));
    }

    public function test_the_wrong_code_is_refused(): void
    {
        $this->clock->set('@59');

        $this->assertNull($this->totp->verify(self::RFC_SECRET, '287083'));
    }

    public function test_a_secret_written_back_by_hand_still_reads(): void
    {
        $this->clock->set('@59');
        $written = strtolower(implode(' ', str_split(self::RFC_SECRET, 4))) . '====';

        $this->assertSame('287082', $this->totp->code($written));
    }

    /** @return iterable<string, array{string, string}> */
    public static function badSecrets(): iterable
    {
        yield 'empty' => ['', 'must be base32'];
        yield 'padding alone' => ['====', 'must be base32'];
        yield 'a digit outside base32' => ['GEZDGNBVGY3TQOJ1', 'must be base32'];
        yield 'too short' => ['GEZDGNBVGY3TQOJ', 'at least 80 bits'];
    }

    #[DataProvider('badSecrets')]
    public function test_a_secret_that_is_not_base32_is_refused(string $secret, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->totp->code($secret);
    }

    public function test_sixteen_characters_is_enough(): void
    {
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->totp->code('GEZDGNBVGY3TQOJQ'));
    }

    public function test_the_uri_is_what_an_authenticator_app_scans(): void
    {
        $this->assertSame(
            'otpauth://totp/Acme%20Co:will%40example.com?secret=' . self::RFC_SECRET
            . '&issuer=Acme%20Co&algorithm=SHA1&digits=6&period=30',
            $this->totp->uri(self::RFC_SECRET, 'will@example.com', 'Acme Co'),
        );
    }

    public function test_the_uri_refuses_a_secret_no_app_could_read(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->totp->uri('not base32!', 'will@example.com', 'Acme');
    }
}
