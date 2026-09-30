<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Notifications;

use Hydra\Admin\Notifications\NotificationStoreInterface;
use Hydra\Admin\Testing\ArrayNotificationStore;
use Hydra\Admin\Testing\NotificationStoreContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ArrayNotificationStore::class)]
final class ArrayNotificationStoreTest extends NotificationStoreContractTestCase
{
    private ArrayNotificationStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayNotificationStore;
    }

    protected function store(): NotificationStoreInterface
    {
        return $this->store;
    }

    protected function user(): int
    {
        return 7;
    }

    protected function otherUser(): int
    {
        return 8;
    }
}
