<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Live;

use Hydra\Admin\AdminController;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\Tests\Support\TicketSource;
use Hydra\Admin\Tests\Support\TicketsModule;
use Hydra\Admin\ViewModels\ListViewModel;
use Hydra\Http\HtmxResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A list that refetches itself when its module changes: one hidden element
 * in the body, asking for the list exactly as it is shown, into the body.
 */
#[CoversClass(AdminController::class)]
#[CoversClass(ListViewModel::class)]
final class LiveListTest extends TestCase
{
    public function test_a_live_list_listens_on_its_module_and_refetches_itself_as_shown(): void
    {
        $body = $this->list('/admin/users?sort=id&dir=desc&page=1', live: true);

        $element = $this->element($body);
        $this->assertNotNull($element, 'A live list carries one listening element.');
        $this->assertStringContainsString('data-stream="module.users"', $element);
        $this->assertStringContainsString('hx-get="/admin/users?sort=id&amp;dir=desc&amp;_live=1"', $element);
        $this->assertStringContainsString('hx-trigger="sse:module.users delay:500ms"', $element);
        $this->assertStringContainsString('hx-target="#admin-body"', $element);
        $this->assertMatchesRegularExpression('/hx-nonce="[^"]+"/', $element);
        $this->assertStringContainsString(' hidden', $element);
        $this->assertStringNotContainsString('hx-push-url', $element);
        $this->assertSame(1, substr_count($body, 'data-stream='));
    }

    public function test_the_listening_element_keeps_the_page_it_is_on(): void
    {
        $element = (string) $this->element($this->list('/admin/users?page=2', live: true));

        // The module's default order is part of what is shown, so it rides along.
        $this->assertStringContainsString('hx-get="/admin/users?sort=id&amp;dir=asc&amp;page=2&amp;_live=1"', $element);
    }

    public function test_a_list_that_is_not_live_is_rendered_as_before(): void
    {
        $body = $this->list('/admin/users?page=1', live: false);

        $this->assertNull($this->element($body));
        $this->assertStringNotContainsString('data-stream', $body);
        $this->assertStringNotContainsString('_live', $body);
    }

    public function test_a_live_refetch_counts_the_tallies_again(): void
    {
        // The bar's tallies sit in the browser's cache; the row that changed
        // is very likely one of them, as after a write.
        $admin = new AdminHarness(
            [TicketsModule::class => new TicketsModule, TicketSource::class => new TicketSource],
            [TicketsModule::class],
            live: true,
        );

        $read = (string) $admin->controller->list($admin->request('GET', '/admin/tickets'))->getBody();
        $refetch = (string) $admin->controller->list($admin->request('GET', '/admin/tickets?_live=1'))->getBody();

        $this->assertStringNotContainsString('_fresh=', $read);
        $this->assertStringContainsString('_fresh=', $refetch);
    }

    public function test_a_live_refetch_pushes_no_url_and_reads_the_same_rows(): void
    {
        $admin = $this->admin(true);

        $plain = $admin->controller->list($admin->request('GET', '/admin/users?page=1', ['HX-Request' => 'true', 'HX-Target' => 'admin-body']));
        $live = $admin->controller->list($admin->request('GET', '/admin/users?page=1&_live=1', ['HX-Request' => 'true', 'HX-Target' => 'admin-body']));

        $this->assertNull(HtmxResponse::directive($live, 'push-url'));
        $this->assertSame(
            $this->rows((string) $plain->getBody()),
            $this->rows((string) $live->getBody()),
        );
    }

    private function list(string $path, bool $live): string
    {
        $admin = $this->admin($live);

        return (string) $admin->controller->list($admin->request('GET', $path))->getBody();
    }

    private function admin(bool $live): AdminHarness
    {
        return new AdminHarness(
            [CrudUsersModule::class => new CrudUsersModule, CrudUserSource::class => new CrudUserSource],
            [CrudUsersModule::class],
            live: $live,
        );
    }

    private function element(string $body): ?string
    {
        return preg_match('/<div[^>]*data-stream="[^"]*"[^>]*>/', $body, $m) === 1 ? $m[0] : null;
    }

    private function rows(string $body): string
    {
        preg_match('~<tbody>.*?</tbody>~s', $body, $m);

        return $m[0] ?? '';
    }
}
