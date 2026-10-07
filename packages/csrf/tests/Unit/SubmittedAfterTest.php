<?php

declare(strict_types=1);

namespace Hydra\Csrf\Tests\Unit;

use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Csrf\Rules\SubmittedAfter;
use Hydra\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubmittedAfter::class)]
final class SubmittedAfterTest extends TestCase
{
    private const KEY_HEX = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';
    private const OLD_KEY_HEX = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';

    private const TOO_FAST = 'That was quick. Please check your message and send it again.';
    private const EXPIRED = 'This form has expired. Please send it again.';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock;
    }

    public function test_a_form_posted_after_the_minimum_passes(): void
    {
        $started = $this->started();

        $this->clock->advance('+3 seconds');

        $this->assertNull($this->check($started));
    }

    /** @return iterable<string, array{int}> */
    public static function tooSoon(): iterable
    {
        yield 'at once' => [0];
        yield 'a second short' => [2];
    }

    #[DataProvider('tooSoon')]
    public function test_a_form_posted_sooner_than_the_minimum_is_too_fast(int $seconds): void
    {
        $started = $this->started();

        $this->clock->advance("+{$seconds} seconds");

        $this->assertSame(self::TOO_FAST, $this->check($started));
    }

    public function test_a_form_posted_at_the_maximum_age_passes_and_a_second_later_has_expired(): void
    {
        $started = $this->started();

        $this->clock->advance('+86400 seconds');
        $this->assertNull($this->check($started));

        $this->clock->advance('+1 seconds');
        $this->assertSame(self::EXPIRED, $this->check($started));
    }

    public function test_a_start_time_in_the_future_has_expired(): void
    {
        $this->clock->advance('+60 seconds');
        $started = $this->started();
        $this->clock->set(FrozenClock::DEFAULT);

        $this->assertSame(self::EXPIRED, $this->check($started));
    }

    /** @return iterable<string, array{mixed}> */
    public static function forged(): iterable
    {
        $now = (string) (new FrozenClock)->now()->getTimestamp();

        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'unsigned' => [$now];
        yield 'an array' => [[$now]];
        yield 'signed by another key' => [Signer::fromHex(self::OLD_KEY_HEX)->sign(SubmittedAfter::PREFIX . $now)];
        yield 'signed without the prefix' => [Signer::fromHex(self::KEY_HEX)->sign($now)];
        yield 'a signed non-number' => [Signer::fromHex(self::KEY_HEX)->sign(SubmittedAfter::PREFIX . 'soon')];
        yield 'a signed negative number' => [Signer::fromHex(self::KEY_HEX)->sign(SubmittedAfter::PREFIX . '-5')];
    }

    #[DataProvider('forged')]
    public function test_a_missing_or_forged_start_time_has_expired(mixed $value): void
    {
        $this->clock->advance('+10 seconds');

        $this->assertSame(self::EXPIRED, $this->check($value));
    }

    public function test_a_start_time_signed_with_a_previous_key_passes(): void
    {
        $started = (new SubmittedAfter(Signer::fromHex(self::OLD_KEY_HEX), $this->clock))->stamp();
        $rotated = new SubmittedAfter(Signer::fromHex(self::KEY_HEX, [self::OLD_KEY_HEX]), $this->clock);

        $this->clock->advance('+5 seconds');

        $this->assertNull($this->validate($rotated, $started));
    }

    public function test_the_limits_and_messages_can_be_changed(): void
    {
        $rule = new SubmittedAfter(
            $this->signer(),
            $this->clock,
            minSeconds: 10,
            maxAge: 60,
            tooFast: 'Slow down.',
            expired: 'Stale.',
        );
        $started = $rule->stamp();

        $this->clock->advance('+9 seconds');
        $this->assertSame('Slow down.', $this->validate($rule, $started));

        $this->clock->advance('+1 seconds');
        $this->assertNull($this->validate($rule, $started));

        $this->clock->advance('+51 seconds');
        $this->assertSame('Stale.', $this->validate($rule, $started));
    }

    public function test_a_zero_minimum_accepts_an_instant_post(): void
    {
        $rule = new SubmittedAfter($this->signer(), $this->clock, minSeconds: 0);

        $this->assertNull($this->validate($rule, $rule->stamp()));
    }

    public function test_a_negative_minimum_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minSeconds must be 0 or more; got -1.');

        new SubmittedAfter($this->signer(), $this->clock, minSeconds: -1);
    }

    public function test_a_maximum_age_not_above_the_minimum_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxAge must be greater than minSeconds (3); got 3.');

        new SubmittedAfter($this->signer(), $this->clock, minSeconds: 3, maxAge: 3);
    }

    private function started(): string
    {
        return $this->rule()->stamp();
    }

    private function check(mixed $value): ?string
    {
        return $this->validate($this->rule(), $value);
    }

    private function validate(SubmittedAfter $rule, mixed $value): ?string
    {
        $data = $value === null ? [] : ['_started' => $value];

        return (new Validator)->validate($data, ['_started' => [$rule]])->first('_started');
    }

    private function rule(): SubmittedAfter
    {
        return new SubmittedAfter($this->signer(), $this->clock);
    }

    private function signer(): Signer
    {
        return Signer::fromHex(self::KEY_HEX);
    }
}
