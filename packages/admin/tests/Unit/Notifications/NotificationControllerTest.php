<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Notifications;

use Hydra\Admin\Live\LiveAdmin;
use Hydra\Admin\Notifications\Notice;
use Hydra\Admin\Notifications\NotificationController;
use Hydra\Admin\Notifications\Notifier;
use Hydra\Admin\Testing\ArrayNotificationStore;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\UsersModule;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Auth\Testing\FakeGuard;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Http\Exceptions\NotFoundException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/** The bell's four requests, each answering for the signed-in user alone. */
#[CoversClass(NotificationController::class)]
#[CoversClass(Notifier::class)]
final class NotificationControllerTest extends TestCase
{
    private ArrayNotificationStore $store;
    private FakeBroadcaster $broadcaster;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->store = new ArrayNotificationStore;
        $this->broadcaster = new FakeBroadcaster;
        $this->clock = new FrozenClock('2026-09-30 12:00:00 UTC');
    }

    public function test_the_badge_counts_the_signed_in_users_unread_notices(): void
    {
        $this->store->add(7, new Notice('a'), $this->clock->now());
        $this->store->add(7, new Notice('b'), $this->clock->now());
        $this->store->add(8, new Notice('theirs'), $this->clock->now());

        $body = $this->body($this->controller(7)->badge($this->get('/admin/notifications/badge?place=topbar')));

        $this->assertStringContainsString('id="admin-bell-badge-topbar"', $body);
        $this->assertMatchesRegularExpression('~>\s*2\s*<~', $body);
        $this->assertStringContainsString('2 unread notifications', $body);
    }

    public function test_the_badge_is_empty_at_zero_and_the_place_defaults_to_the_sidebar(): void
    {
        $body = $this->body($this->controller(7)->badge($this->get('/admin/notifications/badge?place=elsewhere')));

        $this->assertStringContainsString('id="admin-bell-badge-sidebar"', $body);
        $this->assertStringContainsString('No unread notifications', $body);
        $this->assertStringNotContainsString('admin-bell-count', $body);
    }

    public function test_a_live_badge_refetches_itself_on_the_users_topic(): void
    {
        $body = $this->body($this->controller(7, live: true)->badge($this->get('/admin/notifications/badge?place=sidebar')));

        $this->assertStringContainsString('data-stream="user.7"', $body);
        $this->assertStringContainsString('hx-get="/admin/notifications/badge?place=sidebar"', $body);
        $this->assertStringContainsString('hx-trigger="sse:user.7 delay:300ms"', $body);
        $this->assertStringContainsString('hx-swap="outerHTML"', $body);
        $this->assertMatchesRegularExpression('/hx-nonce="[^"]+"/', $body);
    }

    public function test_a_badge_that_is_not_live_does_not_listen(): void
    {
        $body = $this->body($this->controller(7)->badge($this->get('/admin/notifications/badge')));

        $this->assertStringNotContainsString('data-stream', $body);
        $this->assertStringNotContainsString('hx-trigger', $body);
    }

    public function test_the_list_is_the_users_latest_ten_newest_first_and_escaped(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->store->add(7, new Notice("notice {$i}"), $this->clock->now());
        }
        $this->store->add(7, new Notice('<script>alert(1)</script>', 'body & more'), $this->clock->now());
        $this->store->add(8, new Notice('theirs'), $this->clock->now());

        $body = $this->body($this->controller(7)->list($this->get('/admin/notifications')));

        $this->assertSame(10, substr_count($body, 'class="admin-bell-item'));
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringContainsString('body &amp; more', $body);
        $this->assertStringNotContainsString('theirs', $body);
        $this->assertStringNotContainsString('notice 3<', $body);
        $this->assertStringContainsString('action="/admin/notifications/13/read"', $body);
        $this->assertStringContainsString('name="_token"', $body);
        $this->assertStringContainsString('hx-post="/admin/notifications/read-all"', $body);
    }

    public function test_an_empty_list_says_so_and_offers_nothing_to_mark(): void
    {
        $body = $this->body($this->controller(7)->list($this->get('/admin/notifications')));

        $this->assertStringContainsString('No notifications yet.', $body);
        $this->assertStringNotContainsString('read-all', $body);
    }

    public function test_reading_one_marks_it_tells_the_users_pages_and_follows_its_link(): void
    {
        $id = $this->store->add(7, new Notice('a', url: '/admin/settings/security'), $this->clock->now());
        $this->store->add(7, new Notice('b'), $this->clock->now());

        $response = $this->controller(7, live: true)->read($this->post("/admin/notifications/{$id}/read", $id));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/settings/security', $response->getHeaderLine('Location'));
        $this->assertSame(1, $this->store->unreadCount(7));
        $this->broadcaster->assertPublished('user.7', 'notification', static fn (Envelope $e): bool => $e->data === ['unread' => 1], times: 1);
    }

    public function test_reading_one_without_a_link_goes_to_the_admin(): void
    {
        $id = $this->store->add(7, new Notice('a'), $this->clock->now());

        $response = $this->controller(7)->read($this->post("/admin/notifications/{$id}/read", $id));

        $this->assertSame('/admin', $response->getHeaderLine('Location'));
    }

    public function test_another_users_notice_is_not_found_and_stays_unread(): void
    {
        $theirs = $this->store->add(8, new Notice('theirs', url: '/somewhere'), $this->clock->now());

        try {
            $this->controller(7)->read($this->post("/admin/notifications/{$theirs}/read", $theirs));
            $this->fail('Another user\'s notice must be not found.');
        } catch (NotFoundException) {
        }

        $this->assertSame(1, $this->store->unreadCount(8));
        $this->broadcaster->assertNothingPublished();
    }

    public function test_reading_all_marks_them_answers_with_the_list_and_tells_the_users_pages(): void
    {
        $this->store->add(7, new Notice('a'), $this->clock->now());
        $this->store->add(7, new Notice('b'), $this->clock->now());

        $body = $this->body($this->controller(7, live: true)->readAll($this->post('/admin/notifications/read-all')));

        $this->assertSame(0, $this->store->unreadCount(7));
        $this->assertSame(2, substr_count($body, 'class="admin-bell-item'));
        $this->assertStringNotContainsString('is-unread', $body);
        $this->broadcaster->assertPublished('user.7', 'notification', static fn (Envelope $e): bool => $e->data === ['unread' => 0], times: 1);
    }

    public function test_a_guest_is_not_found(): void
    {
        $this->expectException(NotFoundException::class);

        $this->controller(null)->badge($this->get('/admin/notifications/badge'));
    }

    private function controller(?int $user, bool $live = false): NotificationController
    {
        $harness = new AdminHarness([UsersModule::class => new UsersModule, ArraySource::class => new ArraySource], [UsersModule::class]);

        return new NotificationController(
            $this->store,
            new Notifier($this->store, $this->clock, $live ? $this->broadcaster : null),
            $user === null ? FakeGuard::guest() : FakeGuard::signedInAs(new FakeUser($user)),
            $harness->renderer,
            $harness->responder,
            $this->clock,
            new LiveAdmin($live),
            '/admin',
        );
    }

    private function get(string $uri): ServerRequestInterface
    {
        $request = (new Psr17Factory)->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $request->withQueryParams($query);
    }

    private function post(string $uri, ?string $id = null): ServerRequestInterface
    {
        $request = (new Psr17Factory)->createServerRequest('POST', $uri);

        return $id === null ? $request : $request->withAttribute('id', $id);
    }

    private function body(\Psr\Http\Message\ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
