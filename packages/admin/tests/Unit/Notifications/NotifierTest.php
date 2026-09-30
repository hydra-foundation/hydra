<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Notifications;

use Hydra\Admin\Notifications\Notice;
use Hydra\Admin\Notifications\Notifier;
use Hydra\Admin\Testing\ArrayNotificationStore;
use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** A notice kept for a user, and their open pages told at once. */
#[CoversClass(Notifier::class)]
final class NotifierTest extends TestCase
{
    public function test_it_stores_the_notice_now_and_returns_its_id(): void
    {
        $store = new ArrayNotificationStore;
        $clock = new FrozenClock('2026-09-30 12:00:00 UTC');

        $id = (new Notifier($store, $clock))->notify(7, new Notice('Paid'));

        [$stored] = $store->latest(7, 1);
        $this->assertSame($id, $stored->id);
        $this->assertSame('2026-09-30 12:00:00', $stored->createdAt->format('Y-m-d H:i:s'));
    }

    public function test_it_tells_the_users_pages_how_many_are_unread(): void
    {
        $store = new ArrayNotificationStore;
        $broadcaster = new FakeBroadcaster;
        $notifier = new Notifier($store, new FrozenClock, $broadcaster);

        $notifier->notify(7, new Notice('one'));
        $notifier->notify(7, new Notice('two'));

        $broadcaster->assertPublished('user.7', 'notification', static fn (Envelope $e): bool => $e->data === ['unread' => 2], times: 1);
        $this->assertCount(2, $broadcaster->published());
    }

    public function test_the_broadcast_carries_no_title_or_body(): void
    {
        $broadcaster = new FakeBroadcaster;

        (new Notifier(new ArrayNotificationStore, new FrozenClock, $broadcaster))->notify('abc', new Notice('Secret title', 'secret body'));

        $this->assertSame(['unread' => 1], $broadcaster->published()[0]->data);
        $this->assertSame('user.abc', $broadcaster->published()[0]->topic);
    }

    public function test_an_id_no_topic_can_be_made_from_is_stored_and_not_broadcast(): void
    {
        $store = new ArrayNotificationStore;
        $broadcaster = new FakeBroadcaster;

        (new Notifier($store, new FrozenClock, $broadcaster))->notify('Ada Lovelace', new Notice('Paid'));

        $this->assertSame(1, $store->unreadCount('Ada Lovelace'));
        $broadcaster->assertNothingPublished();
    }

    public function test_without_a_broadcaster_it_only_stores(): void
    {
        $store = new ArrayNotificationStore;

        (new Notifier($store, new FrozenClock))->notify(7, new Notice('Paid'));

        $this->assertSame(1, $store->unreadCount(7));
    }
}
