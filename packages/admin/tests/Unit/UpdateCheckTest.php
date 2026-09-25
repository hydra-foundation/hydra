<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Testing\FakeReleaseFeed;
use Hydra\Admin\Updates\Standing;
use Hydra\Admin\Updates\Update;
use Hydra\Admin\Updates\UpdateCheck;
use Hydra\Admin\Widgets\Status;
use Hydra\Admin\Widgets\UpdatesWidget;
use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\Testing\FakeStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateCheck::class)]
#[CoversClass(Update::class)]
#[CoversClass(UpdatesWidget::class)]
#[CoversClass(FakeReleaseFeed::class)]
final class UpdateCheckTest extends TestCase
{
    public function test_the_newest_release_installed_is_current(): void
    {
        $update = $this->check('0.9.7', FakeReleaseFeed::releases(['0.8.4', '0.9.7']))->update();

        $this->assertSame(Standing::Current, $update->standing);
        $this->assertFalse($update->available());
    }

    public function test_a_newer_release_in_the_installed_series_is_a_patch(): void
    {
        $update = $this->check('0.9.7', FakeReleaseFeed::releases(['0.9.9']))->update();

        $this->assertSame(Standing::Patch, $update->standing);
        $this->assertSame('0.9.9', $update->newest);
        $this->assertFalse($update->security);
        $this->assertSame('https://hydra.example/docs/changelog.html#v0-9-9', $update->notes);
    }

    public function test_a_patch_is_offered_before_a_newer_series(): void
    {
        // ^0.8 reaches 0.8.5 with no work, and that is the fix an 0.8 install can take today.
        $update = $this->check('0.8.2', FakeReleaseFeed::releases(['0.8.5', '0.9.7']))->update();

        $this->assertSame(Standing::Patch, $update->standing);
        $this->assertSame('0.8.5', $update->newest);
    }

    public function test_a_newer_series_is_offered_with_its_upgrade_notes(): void
    {
        $update = $this->check('0.8.5', FakeReleaseFeed::releases(['0.8.5', '0.10.0']))->update();

        $this->assertSame(Standing::Series, $update->standing);
        $this->assertSame('0.10.0', $update->newest);
        $this->assertSame('https://hydra.example/docs/changelog.html#v0-10-0', $update->notes);
    }

    public function test_a_skipped_security_release_marks_the_patch(): void
    {
        $feed = FakeReleaseFeed::releases(['0.9.8', '0.9.9'], security: ['0.9.8']);

        $this->assertTrue($this->check('0.9.7', $feed)->update()->security);
        $this->assertFalse($this->check('0.9.8', $feed)->update()->security);
    }

    public function test_a_security_release_in_another_series_does_not_mark_this_one(): void
    {
        $feed = FakeReleaseFeed::releases(['0.8.5', '0.9.8'], security: ['0.9.8']);

        $this->assertFalse($this->check('0.8.2', $feed)->update()->security);
    }

    public function test_a_leading_v_is_read_as_the_release_it_names(): void
    {
        $this->assertSame(Standing::Current, $this->check('v0.9.7', FakeReleaseFeed::releases(['0.9.7']))->update()->standing);
    }

    public function test_a_branch_install_is_not_compared_and_not_fetched(): void
    {
        $feed = FakeReleaseFeed::releases(['0.9.7']);

        $this->assertSame(Standing::Unreleased, $this->check('dev-main', $feed)->update()->standing);
        $this->assertSame(0, $feed->fetches);
    }

    public function test_switched_off_it_never_fetches(): void
    {
        $feed = FakeReleaseFeed::releases(['0.9.9']);

        $this->assertSame(Standing::Off, $this->check('0.9.7', $feed, enabled: false)->update()->standing);
        $this->assertSame(0, $feed->fetches);
    }

    public function test_the_feed_is_fetched_once_and_then_read_from_the_store(): void
    {
        $feed = FakeReleaseFeed::releases(['0.9.9']);
        $store = new ArrayStore;

        $this->check('0.9.7', $feed, $store)->update();
        $update = $this->check('0.9.7', $feed, $store)->update();

        $this->assertSame(Standing::Patch, $update->standing);
        $this->assertSame(1, $feed->fetches);
    }

    public function test_a_failed_fetch_is_remembered_rather_than_retried_per_page(): void
    {
        $feed = new FakeReleaseFeed(null);
        $store = new ArrayStore;

        $this->assertSame(Standing::Unknown, $this->check('0.9.7', $feed, $store)->update()->standing);
        $this->assertSame(Standing::Unknown, $this->check('0.9.7', $feed, $store)->update()->standing);
        $this->assertSame(1, $feed->fetches);
        $this->assertLessThanOrEqual(3600, $store->ttl('admin:updates:feed'));
    }

    public function test_a_feed_with_no_usable_latest_is_a_failure(): void
    {
        foreach ([[], ['latest' => 'soon'], ['latest' => 97], ['series' => ['0.9' => '0.9.9']]] as $body) {
            $this->assertSame(Standing::Unknown, $this->check('0.9.7', new FakeReleaseFeed($body))->update()->standing);
        }
    }

    public function test_malformed_entries_are_dropped_and_the_rest_is_read(): void
    {
        $update = $this->check('0.9.7', new FakeReleaseFeed([
            'latest' => '0.9.9',
            'series' => ['0.9' => '0.9.9', '0.8' => '0.9.1', '0.7' => 'next'],
            'security' => ['0.9.8', 'yesterday', 12],
            'notes' => 'javascript:alert(1)',
        ]))->update();

        $this->assertSame(Standing::Patch, $update->standing);
        $this->assertTrue($update->security);
        $this->assertNull($update->notes);
    }

    public function test_an_unreachable_store_skips_the_fetch(): void
    {
        $feed = FakeReleaseFeed::releases(['0.9.9']);

        $this->assertSame(Standing::Unknown, $this->check('0.9.7', $feed, (new FakeStore)->failAll())->update()->standing);
        $this->assertSame(0, $feed->fetches);
    }

    public function test_the_card_says_how_to_reach_a_patch(): void
    {
        $card = (new UpdatesWidget($this->check('0.9.7', FakeReleaseFeed::releases(['0.9.9']))))->present();

        $this->assertSame(Status::Warning, $card['status']);
        $this->assertSame('0.9.9', $card['headline']);
        $this->assertStringContainsString('hydra:upgrade', $card['note']);
        $this->assertSame('Release notes', $card['link']['text']);
    }

    public function test_the_card_raises_a_security_patch_to_down(): void
    {
        $card = (new UpdatesWidget($this->check('0.9.7', FakeReleaseFeed::releases(['0.9.8'], security: ['0.9.8']))))->present();

        $this->assertSame(Status::Down, $card['status']);
        $this->assertSame('security fix available', $card['caption']);
    }

    public function test_the_card_names_the_constraint_a_new_series_escapes(): void
    {
        $card = (new UpdatesWidget($this->check('0.9.7', FakeReleaseFeed::releases(['0.10.0']))))->present();

        $this->assertSame('^0.9 does not reach 0.10 on its own.', $card['note']);
        $this->assertSame('Upgrade notes', $card['link']['text']);
    }

    public function test_every_standing_has_a_card(): void
    {
        $cases = [
            [$this->check('0.9.7', new FakeReleaseFeed, enabled: false), Status::Ok],
            [$this->check('dev-main', new FakeReleaseFeed), Status::Ok],
            [$this->check('0.9.7', new FakeReleaseFeed), Status::Warning],
            [$this->check('0.9.7', FakeReleaseFeed::releases(['0.9.7'])), Status::Ok],
        ];

        foreach ($cases as [$check, $status]) {
            $this->assertSame($status, (new UpdatesWidget($check))->present()['status']);
        }
    }

    private function check(string $installed, FakeReleaseFeed $feed, ?StoreInterface $store = null, bool $enabled = true): UpdateCheck
    {
        return new UpdateCheck($feed, $store ?? new ArrayStore, $installed, $enabled);
    }
}
