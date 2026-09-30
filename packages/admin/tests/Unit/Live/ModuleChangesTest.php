<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Live;

use Hydra\Admin\Live\ModuleChanges;
use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A write made outside the admin, told to the module's open lists in one call
 * that knows neither the topic's spelling nor whether broadcast is installed.
 */
#[CoversClass(ModuleChanges::class)]
final class ModuleChangesTest extends TestCase
{
    public function test_a_row_is_published_on_its_module_by_id(): void
    {
        $broadcaster = new FakeBroadcaster;

        (new ModuleChanges($broadcaster))->publish('invoices', 7);

        $this->assertPublished($broadcaster, 'module.invoices', ['id' => 7]);
    }

    public function test_an_action_travels_ahead_of_the_id(): void
    {
        $broadcaster = new FakeBroadcaster;

        (new ModuleChanges($broadcaster))->publish('invoices', '7', 'invoice.paid');

        $this->assertPublished($broadcaster, 'module.invoices', ['action' => 'invoice.paid', 'id' => '7']);
    }

    public function test_a_change_to_many_rows_names_none(): void
    {
        $broadcaster = new FakeBroadcaster;

        (new ModuleChanges($broadcaster))->publish('mail');

        $this->assertPublished($broadcaster, 'module.mail', ['id' => null]);
    }

    public function test_without_a_broadcaster_it_does_nothing(): void
    {
        $this->expectNotToPerformAssertions();

        (new ModuleChanges)->publish('invoices', 7);
    }

    /** @param array<string, mixed> $data */
    private function assertPublished(FakeBroadcaster $broadcaster, string $topic, array $data): void
    {
        $broadcaster->assertPublished($topic, 'changed', static fn (Envelope $e): bool => $e->data === $data, times: 1);
        $this->assertCount(1, $broadcaster->published());
    }
}
