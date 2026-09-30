<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Broadcast\BroadcastServiceProvider;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use Hydra\Broadcast\Testing\FakeBroadcastServiceProvider;
use Hydra\Core\Environment;
use Hydra\Core\Testing\FakeContainer;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeBroadcaster::class)]
#[CoversClass(FakeBroadcastServiceProvider::class)]
final class FakeBroadcasterTest extends TestCase
{
    use WritesEnvironment;

    private FakeBroadcaster $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeBroadcaster;
        $this->fake->publish('module.users', 'changed', ['id' => 7]);
        $this->fake->publish('module.users', 'changed', ['id' => 8]);
        $this->fake->publish('user.7', 'notification');
    }

    public function test_it_records_every_publish_in_order(): void
    {
        $this->assertSame(
            [['module.users', 'changed', ['id' => 7]], ['module.users', 'changed', ['id' => 8]], ['user.7', 'notification', []]],
            array_map(static fn (Envelope $e): array => [$e->topic, $e->event, $e->data], $this->fake->published()),
        );
        $this->assertCount(1, $this->fake->published(static fn (Envelope $e): bool => $e->topic === 'user.7'));
    }

    public function test_it_validates_like_the_real_drivers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fake->publish('module:users', 'changed');
    }

    public function test_it_refuses_an_oversized_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fake->publish('users', 'changed', ['p' => str_repeat('x', 70_000)]);
    }

    public function test_assert_published_passes_on_a_topic_an_event_a_match_and_a_count(): void
    {
        $this->fake->assertPublished('module.users');
        $this->fake->assertPublished('module.users', 'changed');
        $this->fake->assertPublished('module.users', 'changed', static fn (Envelope $e): bool => $e->data === ['id' => 8]);
        $this->fake->assertPublished('module.users', 'changed', times: 2);
        $this->fake->assertPublished('user.7', 'notification', times: 1);
    }

    public function test_assert_published_fails_on_an_unheard_topic(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Nothing matching module.files was published.');

        $this->fake->assertPublished('module.files');
    }

    public function test_assert_published_fails_on_another_event(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Nothing matching deleted on module.users was published.');

        $this->fake->assertPublished('module.users', 'deleted');
    }

    public function test_assert_published_fails_when_nothing_matches(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->fake->assertPublished('module.users', null, static fn (Envelope $e): bool => $e->data === ['id' => 9]);
    }

    public function test_assert_published_fails_on_the_wrong_count(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected 1 matching publishes of changed on module.users; 2 were published.');

        $this->fake->assertPublished('module.users', 'changed', times: 1);
    }

    public function test_assert_not_published_passes_on_an_unheard_topic_or_event(): void
    {
        $this->fake->assertNotPublished('module.files');
        $this->fake->assertNotPublished('user.7', 'changed');
    }

    public function test_assert_not_published_fails_on_a_heard_topic(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('2 publishes of module.users were made.');

        $this->fake->assertNotPublished('module.users');
    }

    public function test_assert_not_published_fails_on_a_heard_event(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('1 publishes of notification on user.7 were made.');

        $this->fake->assertNotPublished('user.7', 'notification');
    }

    public function test_assert_nothing_published(): void
    {
        (new FakeBroadcaster)->assertNothingPublished();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('3 broadcasts were published.');

        $this->fake->assertNothingPublished();
    }

    public function test_the_fake_provider_overrides_the_real_one(): void
    {
        $container = new FakeContainer([Environment::class => $this->environment(['BROADCAST_DRIVER' => 'log'])]);
        (new BroadcastServiceProvider)->register($container);
        (new FakeBroadcastServiceProvider)->register($container);

        $fake = $container->get(FakeBroadcaster::class);

        $this->assertSame($fake, $container->get(BroadcasterInterface::class));
    }
}
