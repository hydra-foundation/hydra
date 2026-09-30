<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Broadcast\StreamGrant;
use Hydra\Broadcast\StreamToken;
use Hydra\Broadcast\TokenState;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FrozenClock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The listen token: what a browser shows the hub to be let in, and the only
 * thing the hub knows about who is listening. It reads no session, so a
 * token that opens when it should not is a stream anyone can hear.
 */
#[CoversClass(StreamToken::class)]
#[CoversClass(StreamGrant::class)]
final class StreamTokenTest extends TestCase
{
    private const KEY = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    private FrozenClock $clock;
    private StreamToken $tokens;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-09-30 12:00:00 UTC');
        $this->tokens = new StreamToken(Signer::fromHex(self::KEY), $this->clock);
    }

    public function test_an_int_id_and_its_topics_round_trip(): void
    {
        $grant = $this->tokens->open($this->tokens->mint(7, ['module.users', 'user.7'], 3600));

        $this->assertNotNull($grant);
        $this->assertSame(7, $grant->userId);
        $this->assertSame(['module.users', 'user.7'], $grant->topics);
        $this->assertSame($this->clock->now()->getTimestamp() + 3600, $grant->expiresAt);
    }

    public function test_a_string_id_stays_a_string_even_with_the_separator_in_it(): void
    {
        $grant = $this->tokens->open($this->tokens->mint('abc|7', ['demo'], 60));

        $this->assertNotNull($grant);
        $this->assertSame('abc|7', $grant->userId);
        $this->assertSame(['demo'], $grant->topics);
    }

    public function test_a_numeric_string_id_that_would_not_survive_as_an_int_stays_a_string(): void
    {
        $grant = $this->tokens->open($this->tokens->mint('007', ['demo'], 60));

        $this->assertNotNull($grant);
        $this->assertSame('007', $grant->userId);
    }

    public function test_the_token_is_url_safe(): void
    {
        $token = $this->tokens->mint(str_repeat('x?/+', 20), ['demo'], 60);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    public function test_duplicate_topics_are_granted_once_in_first_seen_order(): void
    {
        $grant = $this->tokens->open($this->tokens->mint(1, ['b', 'a', 'b'], 60));

        $this->assertNotNull($grant);
        $this->assertSame(['b', 'a'], $grant->topics);
    }

    public function test_the_grant_allows_its_topics_and_nothing_else(): void
    {
        $grant = new StreamGrant(1, ['module.users'], 0);

        $this->assertTrue($grant->allows('module.users'));
        $this->assertFalse($grant->allows('module.user'));
        $this->assertFalse($grant->allows('module.users.x'));
        $this->assertFalse($grant->allows('module'));
    }

    public function test_it_is_valid_up_to_and_including_its_last_second_and_expired_after(): void
    {
        $token = $this->tokens->mint(1, ['demo'], 60);

        $this->clock->advance('+60 seconds');
        $this->assertSame(TokenState::Valid, $this->tokens->inspect($token));
        $this->assertNotNull($this->tokens->open($token));

        $this->clock->advance('+1 second');
        $this->assertSame(TokenState::Expired, $this->tokens->inspect($token));
        $this->assertNull($this->tokens->open($token));
    }

    public function test_a_token_signed_under_a_previous_key_still_opens(): void
    {
        $old = new StreamToken(Signer::fromHex(str_repeat('ab', 32)), $this->clock);
        $rotated = new StreamToken(new Signer(
            (string) hex2bin(self::KEY),
            [(string) hex2bin(str_repeat('ab', 32))],
        ), $this->clock);

        $this->assertSame(TokenState::Valid, $rotated->inspect($old->mint(1, ['demo'], 60)));
    }

    /** @return iterable<string, array{callable(StreamToken, Signer): string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [static fn (): string => ''];
        yield 'not base64' => [static fn (): string => '!!!'];
        yield 'garbage' => [static fn (): string => 'aGVsbG8'];
        yield 'another key' => [static fn (StreamToken $t): string => (new StreamToken(Signer::fromHex(str_repeat('cd', 32)), new FrozenClock('2026-09-30 12:00:00 UTC')))->mint(1, ['demo'], 60)];
        yield 'truncated' => [static fn (StreamToken $t): string => substr($t->mint(1, ['demo'], 60), 0, -3)];
        yield 'one character changed' => [static function (StreamToken $t): string {
            $token = $t->mint(1, ['demo'], 60);
            $token[10] = $token[10] === 'A' ? 'B' : 'A';

            return $token;
        }];
        yield 'another purpose' => [static fn (StreamToken $t, Signer $s): string => self::encode($s->sign('reset|9999999999|demo|1'))];
        yield 'too few fields' => [static fn (StreamToken $t, Signer $s): string => self::encode($s->sign('stream|9999999999|demo'))];
        yield 'a non-numeric expiry' => [static fn (StreamToken $t, Signer $s): string => self::encode($s->sign('stream|soon|demo|1'))];
        yield 'no topics' => [static fn (StreamToken $t, Signer $s): string => self::encode($s->sign('stream|9999999999||1'))];
        yield 'an invalid topic' => [static fn (StreamToken $t, Signer $s): string => self::encode($s->sign('stream|9999999999|Demo|1'))];
        yield 'an empty topic in the list' => [static fn (StreamToken $t, Signer $s): string => self::encode($s->sign('stream|9999999999|a,,b|1'))];
        yield 'no id' => [static fn (StreamToken $t, Signer $s): string => self::encode($s->sign('stream|9999999999|demo|'))];
    }

    /** @param callable(StreamToken, Signer): string $token */
    #[DataProvider('invalid')]
    public function test_anything_forged_or_malformed_is_invalid(callable $token): void
    {
        $value = $token($this->tokens, Signer::fromHex(self::KEY));

        $this->assertSame(TokenState::Invalid, $this->tokens->inspect($value));
        $this->assertNull($this->tokens->open($value));
    }

    public function test_a_forged_token_is_invalid_even_when_its_claimed_expiry_has_passed(): void
    {
        // Expired would tell the stream client to fetch a fresh token; a
        // forgery deserves the refusal, not the retry.
        $forged = (new StreamToken(Signer::fromHex(str_repeat('cd', 32)), $this->clock))->mint(1, ['demo'], 1);
        $this->clock->advance('+1 hour');

        $this->assertSame(TokenState::Invalid, $this->tokens->inspect($forged));
    }

    /** @return iterable<string, array{list<string>, int, string}> */
    public static function refusedMints(): iterable
    {
        yield 'no topics' => [[], 60, 'at least one topic'];
        yield 'a bad topic' => [['Demo'], 60, '"Demo" is not a valid broadcast topic'];
        yield 'thirty-three topics' => [array_map(static fn (int $i): string => "t{$i}", range(1, 33)), 60, 'at most 32 topics; got 33'];
        yield 'a zero ttl' => [['demo'], 0, 'at least 1 second; got 0'];
    }

    /** @param list<string> $topics */
    #[DataProvider('refusedMints')]
    public function test_mint_refuses(array $topics, int $ttl, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->tokens->mint(1, $topics, $ttl);
    }

    public function test_thirty_two_topics_and_duplicates_beyond_them_are_fine(): void
    {
        $topics = array_map(static fn (int $i): string => "t{$i}", range(1, 32));

        $grant = $this->tokens->open($this->tokens->mint(1, [...$topics, 't1'], 60));

        $this->assertNotNull($grant);
        $this->assertCount(32, $grant->topics);
    }

    private static function encode(string $signed): string
    {
        return rtrim(strtr(base64_encode($signed), '+/', '-_'), '=');
    }
}
