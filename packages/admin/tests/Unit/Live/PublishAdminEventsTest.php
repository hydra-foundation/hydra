<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Live;

use Hydra\Admin\Criteria;
use Hydra\Admin\Events\ActionTaken;
use Hydra\Admin\Events\Exported;
use Hydra\Admin\Events\RowCreated;
use Hydra\Admin\Events\RowDeleted;
use Hydra\Admin\Events\RowUpdated;
use Hydra\Admin\Live\PublishAdminEvents;
use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An admin write, told to every open list of that module. What changed goes
 * by id only: the page refetches the table, so the broadcast never carries a
 * row, and a module's values are whatever the application declared.
 */
#[CoversClass(PublishAdminEvents::class)]
final class PublishAdminEventsTest extends TestCase
{
    private FakeBroadcaster $broadcaster;
    private PublishAdminEvents $listener;

    protected function setUp(): void
    {
        $this->broadcaster = new FakeBroadcaster;
        $this->listener = new PublishAdminEvents($this->broadcaster);
    }

    public function test_a_created_row_is_published_on_its_module(): void
    {
        ($this->listener)(new RowCreated('users', '7', ['password' => 'secret']));

        $this->assertPublished('module.users', ['action' => 'admin.row_created', 'id' => '7']);
    }

    public function test_an_updated_row_is_published(): void
    {
        ($this->listener)(new RowUpdated('users', '7', ['email' => 'a@b.test'], ['email' => 'old@b.test']));

        $this->assertPublished('module.users', ['action' => 'admin.row_updated', 'id' => '7']);
    }

    public function test_a_deleted_row_is_published(): void
    {
        ($this->listener)(new RowDeleted('access', '3'));

        $this->assertPublished('module.access', ['action' => 'admin.row_deleted', 'id' => '3']);
    }

    public function test_a_row_action_is_published_with_its_row(): void
    {
        ($this->listener)(new ActionTaken('access', 'revoke', '3'));

        $this->assertPublished('module.access', ['action' => 'admin.action', 'id' => '3']);
    }

    public function test_a_module_action_is_published_with_no_row(): void
    {
        ($this->listener)(new ActionTaken('rate-limits', 'release-all'));

        $this->assertPublished('module.rate-limits', ['action' => 'admin.action', 'id' => null]);
    }

    public function test_an_export_changes_nothing_and_publishes_nothing(): void
    {
        ($this->listener)(new Exported('users', new Criteria, 12));

        $this->broadcaster->assertNothingPublished();
    }

    /** @param array<string, mixed> $data */
    private function assertPublished(string $topic, array $data): void
    {
        $this->broadcaster->assertPublished($topic, 'changed', static fn (Envelope $e): bool => $e->data === $data, times: 1);
        $this->assertCount(1, $this->broadcaster->published());
    }
}
