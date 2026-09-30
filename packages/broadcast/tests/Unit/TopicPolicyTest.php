<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Broadcast\TopicPolicy;
use Hydra\Log\Testing\CapturingLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Who may listen to what. Deny by default: a topic nobody registered is heard by nobody. */
#[CoversClass(TopicPolicy::class)]
final class TopicPolicyTest extends TestCase
{
    public function test_an_unregistered_topic_is_refused(): void
    {
        $this->assertFalse((new TopicPolicy)->permits(1, 'demo'));
    }

    public function test_a_literal_pattern_asks_its_check_with_no_params(): void
    {
        $seen = [];
        $policy = (new TopicPolicy)->allow('demo', function (int|string $user, array $params) use (&$seen): bool {
            $seen[] = [$user, $params];

            return $user === 7;
        });

        $this->assertTrue($policy->permits(7, 'demo'));
        $this->assertFalse($policy->permits(8, 'demo'));
        $this->assertSame([[7, []], [8, []]], $seen);
    }

    public function test_placeholders_capture_their_segment_by_name(): void
    {
        $seen = null;
        $policy = (new TopicPolicy)->allow('user.{id}.inbox.{box}', function (int|string $user, array $params) use (&$seen): bool {
            $seen = $params;

            return true;
        });

        $this->assertTrue($policy->permits(1, 'user.7.inbox.main'));
        $this->assertSame(['id' => '7', 'box' => 'main'], $seen);
    }

    public function test_a_placeholder_matches_exactly_one_segment(): void
    {
        $policy = (new TopicPolicy)->allow('module.{slug}', static fn (): bool => true);

        $this->assertTrue($policy->permits(1, 'module.users'));
        $this->assertFalse($policy->permits(1, 'module.users.extra'));
        $this->assertFalse($policy->permits(1, 'module'));
        $this->assertFalse($policy->permits(1, 'modules.users'));
    }

    public function test_the_first_matching_pattern_decides(): void
    {
        $policy = (new TopicPolicy)
            ->allow('module.secrets', static fn (): bool => false)
            ->allow('module.{slug}', static fn (): bool => true);

        $this->assertFalse($policy->permits(1, 'module.secrets'));
        $this->assertTrue($policy->permits(1, 'module.users'));
    }

    public function test_an_invalid_topic_is_refused_without_asking(): void
    {
        $asked = false;
        $policy = (new TopicPolicy)->allow('{any}', function () use (&$asked): bool {
            $asked = true;

            return true;
        });

        $this->assertFalse($policy->permits(1, 'Module:Users'));
        $this->assertFalse($asked);
    }

    public function test_a_check_that_throws_is_a_refusal_and_is_logged(): void
    {
        $logger = new CapturingLogger;
        $policy = (new TopicPolicy($logger))->allow('module.{slug}', static fn (): bool => throw new RuntimeException('no db'));

        $this->assertFalse($policy->permits(3, 'module.users'));
        $this->assertSame(['Topic check for module.{slug} failed on module.users: no db'], $logger->messages());
        $this->assertSame('warning', $logger->records()[0]['level']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badPatterns(): iterable
    {
        yield 'empty' => ['', 'not a valid topic pattern'];
        yield 'uppercase' => ['Module.{slug}', 'not a valid topic pattern'];
        yield 'a placeholder inside a segment' => ['module-{slug}', 'not a valid topic pattern'];
        yield 'a bad placeholder name' => ['module.{Slug}', 'not a valid topic pattern'];
        yield 'an empty placeholder' => ['module.{}', 'not a valid topic pattern'];
        yield 'a repeated placeholder' => ['a.{x}.{x}', 'names {x} twice'];
    }

    #[DataProvider('badPatterns')]
    public function test_a_bad_pattern_is_refused_where_it_is_registered(string $pattern, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new TopicPolicy)->allow($pattern, static fn (): bool => true);
    }
}
