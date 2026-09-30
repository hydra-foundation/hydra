<?php

declare(strict_types=1);

namespace Hydra\Admin\Notifications;

use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Topic;
use Psr\Clock\ClockInterface;

/**
 * Tells a user something in the application: keeps the notice for them, then
 * tells their open pages on `user.{id}` how many they have unread, so the bell
 * updates without a reload.
 *
 * Only the count travels. The title and body stay in the store, read by the
 * bell for whoever is signed in. Mail is not this class's business: a notice
 * that should also reach the inbox is mailed where it is sent.
 */
final readonly class Notifier
{
    public function __construct(
        private NotificationStoreInterface $store,
        private ClockInterface $clock,
        private ?BroadcasterInterface $broadcaster = null,
    ) {}

    /** @return string the stored notice's id */
    public function notify(int|string $userId, Notice $notice): string
    {
        $id = $this->store->add($userId, $notice, $this->clock->now());
        $topic = self::topic($userId);

        // An id no topic can be made from has no page that could listen for
        // it; the notice is kept all the same, and shows on the next load.
        if ($this->broadcaster !== null && Topic::isValid($topic)) {
            $this->broadcaster->publish($topic, 'notification', ['unread' => $this->store->unreadCount($userId)]);
        }

        return $id;
    }

    /** The topic a user's pages listen on for their own notices. */
    public static function topic(int|string $userId): string
    {
        return "user.{$userId}";
    }
}
