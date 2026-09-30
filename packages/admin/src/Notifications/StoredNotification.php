<?php

declare(strict_types=1);

namespace Hydra\Admin\Notifications;

use DateTimeImmutable;

/** A notice as a store holds it: which one, when it came, and whether it has been read. */
final readonly class StoredNotification
{
    public function __construct(
        public string $id,
        public Notice $notice,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $readAt = null,
    ) {}

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
