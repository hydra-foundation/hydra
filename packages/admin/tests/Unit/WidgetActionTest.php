<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Definition;
use Hydra\Admin\Events\ActionTaken;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Screens\WidgetActionScreen;
use Hydra\Admin\Tests\Support\ActionCardsModule;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CardAction;
use Hydra\Admin\Tests\Support\CountPresenter;
use Hydra\Admin\Tests\Support\RefusingCardAction;
use Hydra\Admin\Tests\Support\StatsPresenter;
use Hydra\Admin\Widget;
use Hydra\Admin\WidgetAction;
use Hydra\Authorization\Exceptions\AuthorizationException;
use Hydra\Http\Exceptions\NotFoundException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A button on a dashboard card: the card answers the press by coming back as
 * itself with the outcome in it, the way a list answers its own buttons with
 * itself. Same ability, same refusal, same event as any other admin action.
 */
#[CoversClass(Widget::class)]
#[CoversClass(AdminController::class)]
#[CoversClass(Definition::class)]
#[CoversClass(ModuleRegistry::class)]
#[CoversClass(WidgetAction::class)]
#[CoversClass(WidgetActionScreen::class)]
final class WidgetActionTest extends TestCase
{
    private CardAction $action;

    protected function setUp(): void
    {
        $this->action = new CardAction;
    }

    public function test_a_filled_card_offers_its_buttons_and_the_placeholder_does_not(): void
    {
        $admin = $this->harness();
        $grid = (string) $admin->controller->dashboard($admin->request('GET', '/admin/health'))->getBody();
        $card = $this->get($admin, '/admin/health/w/cache');

        $this->assertStringNotContainsString('/admin/health/w/cache/flush', $grid, 'a card still fetching itself has nothing to act on');
        $this->assertStringContainsString('hx-post="/admin/health/w/cache/flush"', $card);
        $this->assertStringContainsString('hx-target="#admin-widget-cache"', $card);
        $this->assertStringContainsString('hx-confirm="Empty the cache?"', $card);
        $this->assertStringContainsString('>Flush</button>', $card);
        $this->assertStringContainsString('hx-post="/admin/health/w/cache/stall"', $card);
        $this->assertStringContainsString('name="_token"', $card);
    }

    public function test_a_card_with_no_actions_has_no_footer(): void
    {
        $this->assertStringNotContainsString('admin-widget-actions', $this->get($this->harness(), '/admin/health/w/plain'));
    }

    public function test_a_press_runs_the_action_and_the_card_comes_back_with_what_it_said(): void
    {
        $admin = $this->harness();
        $response = $this->post($admin, '/admin/health/w/cache/flush');
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->action->runs);
        $this->assertStringContainsString('id="admin-widget-cache"', $body);
        $this->assertStringContainsString('role="status">Emptied.</div>', $body);
        $this->assertStringContainsString('7 accounts', $body, 'the card is presented again, not left empty');
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function test_a_press_is_announced_as_the_card_s_action(): void
    {
        $admin = $this->harness();
        $this->post($admin, '/admin/health/w/cache/flush');

        $event = $admin->events->first(ActionTaken::class);
        $this->assertSame('health', $event->module);
        $this->assertSame('cache.flush', $event->name);
        $this->assertNull($event->id);
    }

    public function test_a_refusal_is_a_failure_on_the_card_and_announces_nothing(): void
    {
        $admin = $this->harness();
        $response = $this->post($admin, '/admin/health/w/cache/stall');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('role="alert">Not now.</div>', (string) $response->getBody());
        $this->assertSame([], $admin->events->dispatched());
    }

    public function test_a_press_without_htmx_goes_back_to_the_dashboard(): void
    {
        $admin = $this->harness();
        $response = $admin->controller->widgetAction($admin->request('POST', '/admin/health/w/cache/flush'));

        $this->assertSame(1, $this->action->runs);
        $this->assertContains($response->getStatusCode(), [302, 303]);
        $this->assertSame('/admin/health', $response->getHeaderLine('Location'));
    }

    public function test_an_action_the_card_does_not_have_is_not_found(): void
    {
        $this->expectException(NotFoundException::class);

        $this->post($this->harness(), '/admin/health/w/plain/flush');
    }

    public function test_a_card_the_dashboard_does_not_have_is_not_found(): void
    {
        $this->expectException(NotFoundException::class);

        $this->post($this->harness(), '/admin/health/w/nowhere/flush');
    }

    public function test_a_card_s_own_ability_guards_its_buttons(): void
    {
        $this->expectException(AuthorizationException::class);

        try {
            $this->post($this->harness(allowed: false), '/admin/health/w/vault/seal');
        } finally {
            $this->assertSame(0, $this->action->runs);
        }
    }

    public function test_a_dashboard_with_buttons_gets_one_post_route_and_one_without_gets_none(): void
    {
        $routes = array_values(array_filter(
            (new ActionCardsModule)->define()->compile()->screens,
            static fn ($screen): bool => $screen instanceof WidgetActionScreen,
        ));

        $this->assertCount(1, $routes);
        $this->assertSame('POST', $routes[0]->method());
        $this->assertSame('w/{widget}/{action}', $routes[0]->path());
    }

    public function test_two_actions_with_one_name_are_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Widget "cache" has two actions named "flush".');

        Widget::make('cache', 't')->action('flush', 'A', CardAction::class)->action('flush', 'B', CardAction::class);
    }

    public function test_a_name_that_cannot_sit_in_a_url_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Widget "cache": action name "Flush!" may only use a-z, 0-9 and -.');

        Widget::make('cache', 't')->action('Flush!', 'Flush', CardAction::class);
    }

    public function test_a_class_that_is_not_a_module_action_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Widget "cache": action "flush" runs ' . StatsPresenter::class . ', which is not a Hydra\Admin\Contracts\ModuleActionInterface.');

        Widget::make('cache', 't')->action('flush', 'Flush', StatsPresenter::class);
    }

    public function test_the_card_lists_its_actions_in_order(): void
    {
        $widget = Widget::make('cache', 't')
            ->action('flush', 'Flush', CardAction::class, confirm: 'Sure?')
            ->action('warm', 'Warm', CardAction::class);

        $this->assertSame(['flush', 'warm'], array_map(static fn (WidgetAction $a): string => $a->name, $widget->actions()));
        $this->assertSame('Sure?', $widget->actions()[0]->confirm);
        $this->assertNull($widget->actions()[1]->confirm);
        $this->assertSame(CardAction::class, $widget->actions()[0]->runs);
        $this->assertSame('Flush', $widget->actions()[0]->label);
    }

    private function post(AdminHarness $admin, string $path): ResponseInterface
    {
        return $admin->controller->widgetAction($admin->request('POST', $path, ['HX-Request' => 'true', 'HX-Target' => 'admin-widget-cache']));
    }

    private function get(AdminHarness $admin, string $path): string
    {
        return (string) $admin->controller->widget($admin->request('GET', $path))->getBody();
    }

    private function harness(bool $allowed = true): AdminHarness
    {
        return new AdminHarness(
            [
                ActionCardsModule::class => new ActionCardsModule,
                CountPresenter::class => new CountPresenter,
                CardAction::class => $this->action,
                RefusingCardAction::class => new RefusingCardAction,
            ],
            [ActionCardsModule::class],
            allowed: $allowed,
        );
    }
}
