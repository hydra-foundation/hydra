<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Broadcast\Topic;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The naming rules. A topic ends up in a Redis channel name and in a listen
 * token; an event name ends up in an SSE "event:" line and an htmx trigger, so
 * a newline or a colon in either would be a second field smuggled in.
 */
#[CoversClass(Topic::class)]
final class TopicTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validTopics(): iterable
    {
        yield 'one segment' => ['queue'];
        yield 'two segments' => ['module.users'];
        yield 'a numeric segment' => ['user.7'];
        yield 'dashes and underscores' => ['a-b_c.d'];
        yield 'the longest' => [str_repeat('a', 128)];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTopics(): iterable
    {
        yield 'empty' => [''];
        yield 'a leading dot' => ['.x'];
        yield 'a trailing dot' => ['x.'];
        yield 'an empty segment' => ['x..y'];
        yield 'uppercase' => ['Users'];
        yield 'a space' => ['module users'];
        yield 'a colon' => ['module:users'];
        yield 'a newline' => ["users\n"];
        yield 'a wildcard' => ['module.*'];
        yield 'one too long' => [str_repeat('a', 129)];
    }

    /** @return iterable<string, array{string}> */
    public static function validEvents(): iterable
    {
        yield 'a word' => ['changed'];
        yield 'dotted' => ['row.created'];
        yield 'dashes, underscores and digits' => ['a-b_c9'];
        yield 'the longest' => [str_repeat('e', 64)];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEvents(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Changed'];
        yield 'a colon' => ['sse:changed'];
        yield 'a newline' => ["changed\ndata: x"];
        yield 'a space' => ['row created'];
        yield 'one too long' => [str_repeat('e', 65)];
    }

    #[DataProvider('validTopics')]
    public function test_a_valid_topic_passes(string $topic): void
    {
        $this->assertTrue(Topic::isValid($topic));
        Topic::assertValid($topic);
    }

    #[DataProvider('invalidTopics')]
    public function test_an_invalid_topic_is_refused_by_name(string $topic): void
    {
        $this->assertFalse(Topic::isValid($topic));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"%s" is not a valid broadcast topic', $topic));

        Topic::assertValid($topic);
    }

    #[DataProvider('validEvents')]
    public function test_a_valid_event_name_passes(string $event): void
    {
        $this->assertTrue(Topic::isValidEvent($event));
        Topic::assertValidEvent($event);
    }

    #[DataProvider('invalidEvents')]
    public function test_an_invalid_event_name_is_refused_by_name(string $event): void
    {
        $this->assertFalse(Topic::isValidEvent($event));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"%s" is not a valid broadcast event name', $event));

        Topic::assertValidEvent($event);
    }
}
