<?php

declare(strict_types=1);

namespace Hydra\Admin\Notifications;

use DateTimeImmutable;

/**
 * Where each user's notices are kept. The application's to implement, as it
 * does its API tokens: it owns the table. Every method is scoped by user, and
 * {@see \Hydra\Admin\Testing\NotificationStoreContractTestCase} is what proves
 * an implementation never lets one user read or mark another's.
 */
interface NotificationStoreInterface
{
    /** Keeps $notice for $userId, unread, and returns its id. */
    public function add(int|string $userId, Notice $notice, DateTimeImmutable $at): string;

    /** @return list<StoredNotification> newest first, at most $limit */
    public function latest(int|string $userId, int $limit): array;

    public function unreadCount(int|string $userId): int;

    /**
     * Marks the notice read, if it is this user's, and returns it. A notice
     * read before keeps the first time. Null for another user's, or none.
     */
    public function markRead(int|string $userId, string $id, DateTimeImmutable $at): ?StoredNotification;

    /** Marks every unread notice of this user's read, and says how many. */
    public function markAllRead(int|string $userId, DateTimeImmutable $at): int;
}
