<?php

declare(strict_types=1);

namespace Hydra\Admin\Testing;

use DateTimeImmutable;
use Hydra\Admin\Notifications\Notice;
use Hydra\Admin\Notifications\NotificationStoreInterface;
use Hydra\Admin\Notifications\StoredNotification;

/** Notices in memory, for tests and for trying the bell without a table. */
final class ArrayNotificationStore implements NotificationStoreInterface
{
    /** @var array<string, array{user: string, notification: StoredNotification}> keyed by id, in the order added */
    private array $rows = [];

    private int $next = 1;

    public function add(int|string $userId, Notice $notice, DateTimeImmutable $at): string
    {
        $id = (string) $this->next++;
        $this->rows[$id] = ['user' => (string) $userId, 'notification' => new StoredNotification($id, $notice, $at)];

        return $id;
    }

    public function latest(int|string $userId, int $limit): array
    {
        return array_slice(array_reverse($this->mine($userId)), 0, max(0, $limit));
    }

    public function unreadCount(int|string $userId): int
    {
        return count(array_filter($this->mine($userId), static fn (StoredNotification $n): bool => !$n->isRead()));
    }

    public function markRead(int|string $userId, string $id, DateTimeImmutable $at): ?StoredNotification
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]['user'] !== (string) $userId) {
            return null;
        }

        $n = $this->rows[$id]['notification'];

        if (!$n->isRead()) {
            $n = new StoredNotification($n->id, $n->notice, $n->createdAt, $at);
            $this->rows[$id]['notification'] = $n;
        }

        return $n;
    }

    public function markAllRead(int|string $userId, DateTimeImmutable $at): int
    {
        $marked = 0;

        foreach ($this->mine($userId) as $n) {
            if (!$n->isRead()) {
                $this->markRead($userId, $n->id, $at);
                $marked++;
            }
        }

        return $marked;
    }

    public function clearRead(int|string $userId): int
    {
        $cleared = 0;

        foreach ($this->rows as $id => $row) {
            if ($row['user'] === (string) $userId && $row['notification']->isRead()) {
                unset($this->rows[$id]);
                $cleared++;
            }
        }

        return $cleared;
    }

    /** @return list<StoredNotification> oldest first */
    private function mine(int|string $userId): array
    {
        return array_values(array_map(
            static fn (array $row): StoredNotification => $row['notification'],
            array_filter($this->rows, static fn (array $row): bool => $row['user'] === (string) $userId),
        ));
    }
}
