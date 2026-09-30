<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Live;

use Hydra\Admin\AdminController;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CountPresenter;
use Hydra\Admin\Tests\Support\LiveCardsModule;
use Hydra\Admin\Widget;
use Hydra\Broadcast\Topic;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A card that fetches itself again when its topics are broadcast: beside
 * "once, on load" and "every N seconds", a third reason to ask.
 */
#[CoversClass(Widget::class)]
#[CoversClass(AdminController::class)]
final class LiveCardsTest extends TestCase
{
    public function test_a_listening_card_refetches_itself_on_its_topic(): void
    {
        $card = $this->card('listening', live: true);

        $this->assertStringContainsString('data-stream="module.users"', $card);
        $this->assertStringContainsString('hx-get="/admin/live/w/listening"', $card);
        $this->assertStringContainsString('hx-trigger="sse:module.users delay:500ms"', $card);
        $this->assertStringContainsString('hx-swap="outerHTML"', $card);
    }

    public function test_a_card_that_polls_and_listens_does_both_on_each_topic_once(): void
    {
        $card = $this->card('both', live: true);

        $this->assertStringContainsString('data-stream="module.users module.jobs"', $card);
        $this->assertStringContainsString('hx-trigger="every 30s, sse:module.users delay:500ms, sse:module.jobs delay:500ms"', $card);
    }

    public function test_the_summary_strip_listens_like_a_card(): void
    {
        $admin = $this->admin(true);
        $body = (string) $admin->controller->widget($admin->request('GET', '/admin/live/w/totals'))->getBody();
        preg_match('~<div class="admin-summary-card"[^>]*>~s', $body, $m);

        $this->assertStringContainsString('data-stream="module.users"', $m[0] ?? '');
        $this->assertStringContainsString('hx-trigger="sse:module.users delay:500ms"', $m[0] ?? '');

        $still = $this->admin(false);
        $body = (string) $still->controller->widget($still->request('GET', '/admin/live/w/totals'))->getBody();
        $this->assertStringNotContainsString('hx-trigger', $body);
    }

    public function test_the_triggers_are_load_then_poll_then_each_topic(): void
    {
        $widget = Widget::make('x', 'x')->refreshEvery(10)->liveOn('a', 'b');

        $this->assertSame(['load'], $widget->triggers(loading: true, polling: false, listening: false));
        $this->assertSame(['every 10s', 'sse:a delay:500ms', 'sse:b delay:500ms'], $widget->triggers(loading: false, polling: true, listening: true));
        $this->assertSame([], $widget->triggers(loading: false, polling: false, listening: false));
    }

    public function test_a_card_that_does_neither_asks_for_nothing(): void
    {
        $card = $this->card('still', live: true);

        $this->assertStringNotContainsString('hx-get', $card);
        $this->assertStringNotContainsString('hx-trigger', $card);
        $this->assertStringNotContainsString('data-stream', $card);
    }

    public function test_when_the_admin_is_not_live_a_listening_card_is_drawn_as_before(): void
    {
        $this->assertStringNotContainsString('hx-trigger', $this->card('listening', live: false));
        $this->assertStringContainsString('hx-trigger="every 30s"', $this->card('both', live: false));
        $this->assertStringNotContainsString('data-stream', $this->card('both', live: false));
    }

    public function test_the_placeholder_only_loads_and_its_filled_copy_listens(): void
    {
        $admin = $this->admin(true);
        $grid = (string) $admin->controller->dashboard($admin->request('GET', '/admin/live'))->getBody();

        preg_match('~<div class="card admin-widget[^>]*id="admin-widget-listening"[^>]*>~s', $grid, $m);
        $this->assertStringContainsString('hx-trigger="load"', $m[0] ?? '');
        $this->assertStringNotContainsString('data-stream', $m[0] ?? '');
    }

    public function test_the_topics_are_the_declared_ones_in_order_once_each(): void
    {
        $widget = Widget::make('x', 'x')->liveOn('b', 'a')->liveOn('b', 'c');

        $this->assertSame(['b', 'a', 'c'], $widget->topics());
        $this->assertSame([], Widget::make('x', 'x')->topics());
    }

    /** @return iterable<string, array{string}> */
    public static function badTopics(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Module.users'];
        yield 'a colon' => ['module:users'];
        yield 'a space' => ['module users'];
        yield 'an empty segment' => ['module..users'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('badTopics')]
    public function test_a_topic_no_page_could_be_granted_is_refused_where_it_is_declared(string $topic): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Card x cannot listen on \"{$topic}\"");

        Widget::make('x', 'x')->liveOn($topic);
    }

    public function test_a_card_accepts_exactly_the_topics_broadcast_accepts(): void
    {
        // The rules are repeated in Widget so the admin need not require the
        // package; this is what keeps the two copies saying the same thing.
        foreach (['users', 'module.users', 'user.7', 'a-b_c.d', str_repeat('a', 128), '', '.x', 'x.', 'x..y', 'Users', 'a:b', 'a b', "a\n", str_repeat('a', 129)] as $topic) {
            try {
                Widget::make('x', 'x')->liveOn($topic);
                $accepted = true;
            } catch (InvalidArgumentException) {
                $accepted = false;
            }

            $this->assertSame(Topic::isValid($topic), $accepted, "\"{$topic}\"");
        }
    }

    private function card(string $key, bool $live): string
    {
        $admin = $this->admin($live);
        $body = (string) $admin->controller->widget($admin->request('GET', "/admin/live/w/{$key}"))->getBody();
        preg_match('~<div class="card admin-widget[^>]*>~s', $body, $m);

        return $m[0] ?? '';
    }

    private function admin(bool $live): AdminHarness
    {
        return new AdminHarness(
            [LiveCardsModule::class => new LiveCardsModule, CountPresenter::class => new CountPresenter],
            [LiveCardsModule::class],
            live: $live,
        );
    }
}
