<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Broadcast\Envelope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The channel format. The publisher writes it and the hub reads it, and the
 * hub reads whatever is on the channel, so a malformed message must come back
 * as null rather than as an exception that takes the hub down.
 */
#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    public function test_it_round_trips_exactly(): void
    {
        $envelope = new Envelope('module.users', 'changed', [
            'id' => 7,
            'name' => 'Zoë <b>',
            'path' => 'a/b',
            'nested' => ['list' => [1, 2.5, true, null], 'empty' => []],
        ], 1_759_230_000_123);

        $back = Envelope::fromJson($envelope->toJson());

        $this->assertEquals($envelope, $back);
    }

    public function test_the_json_is_the_documented_shape(): void
    {
        $json = (new Envelope('user.7', 'notification', ['n' => 'é/x'], 5))->toJson();

        $this->assertSame('{"topic":"user.7","event":"notification","data":{"n":"é/x"},"at":5}', $json);
    }

    public function test_empty_data_round_trips(): void
    {
        $back = Envelope::fromJson((new Envelope('queue', 'changed', [], 1))->toJson());

        $this->assertNotNull($back);
        $this->assertSame([], $back->data);
    }

    public function test_a_bad_topic_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Users" is not a valid broadcast topic');

        new Envelope('Users', 'changed', [], 1);
    }

    public function test_a_bad_event_name_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid broadcast event name');

        new Envelope('users', "changed\n", [], 1);
    }

    public function test_data_that_cannot_be_encoded_is_refused(): void
    {
        $envelope = new Envelope('users', 'changed', ['bad' => "\xB1\x31"], 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The changed broadcast on users could not be encoded as JSON');

        $envelope->toJson();
    }

    public function test_the_encoded_envelope_may_be_exactly_64_kib(): void
    {
        $json = self::sized(Envelope::MAX_BYTES)->toJson();

        $this->assertSame(Envelope::MAX_BYTES, strlen($json));
    }

    public function test_one_byte_over_64_kib_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The changed broadcast on users is 65537 bytes; the limit is 65536');

        self::sized(Envelope::MAX_BYTES + 1)->toJson();
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'not json' => ['{"topic":'];
        yield 'a list' => ['["users","changed",[],1]'];
        yield 'a scalar' => ['"users"'];
        yield 'no topic' => ['{"event":"changed","data":{},"at":1}'];
        yield 'no event' => ['{"topic":"users","data":{},"at":1}'];
        yield 'no data' => ['{"topic":"users","event":"changed","at":1}'];
        yield 'no time' => ['{"topic":"users","event":"changed","data":{}}'];
        yield 'a numeric topic' => ['{"topic":7,"event":"changed","data":{},"at":1}'];
        yield 'a numeric event' => ['{"topic":"users","event":7,"data":{},"at":1}'];
        yield 'scalar data' => ['{"topic":"users","event":"changed","data":"x","at":1}'];
        yield 'a string time' => ['{"topic":"users","event":"changed","data":{},"at":"1"}'];
        yield 'an invalid topic' => ['{"topic":"Users","event":"changed","data":{},"at":1}'];
        yield 'an invalid event' => ['{"topic":"users","event":"a:b","data":{},"at":1}'];
        yield 'too deep' => ['{"topic":"users","event":"changed","data":' . str_repeat('[', 600) . str_repeat(']', 600) . ',"at":1}'];
    }

    #[DataProvider('malformed')]
    public function test_anything_malformed_reads_as_null(string $json): void
    {
        $this->assertNull(Envelope::fromJson($json));
    }

    /** An envelope whose JSON is exactly $bytes long. */
    private static function sized(int $bytes): Envelope
    {
        $overhead = strlen((new Envelope('users', 'changed', ['p' => ''], 1))->toJson());

        return new Envelope('users', 'changed', ['p' => str_repeat('x', $bytes - $overhead)], 1);
    }
}
