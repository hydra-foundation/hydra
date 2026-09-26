<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Events\ActionTaken;
use Hydra\Admin\Tests\Support\ActionUsersModule;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\RecordingModuleAction;
use Hydra\Admin\Tests\Support\RecordingRowAction;
use Hydra\Authorization\Exceptions\AuthorizationException;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\HtmxResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * An action runs the class it names and answers the way a delete does: a
 * notice over the table, and an announcement only when the work was done.
 */
#[CoversClass(AdminController::class)]
#[CoversClass(ActionTaken::class)]
final class AdminActionTest extends TestCase
{
    private RecordingRowAction $row;
    private RecordingModuleAction $module;
    private AdminHarness $admin;

    protected function setUp(): void
    {
        $this->row = new RecordingRowAction;
        $this->module = new RecordingModuleAction;
        $this->admin = $this->harness($this->row);
    }

    public function test_a_row_action_runs_on_the_row_and_says_what_it_did(): void
    {
        $response = $this->act('/admin/users/2/flag', $this->admin->frame());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['2'], $this->row->ran);
        $this->assertStringContainsString('Row 2 flagged.', (string) $response->getBody());
        $this->assertStringStartsWith('/admin/users', (string) HtmxResponse::directive($response, 'push-url'));
    }

    public function test_a_module_action_runs_once_and_says_what_it_did(): void
    {
        $response = $this->act('/admin/users/flag-all', $this->admin->frame());

        $this->assertSame(1, $this->module->runs);
        $this->assertSame([], $this->row->ran);
        $this->assertStringContainsString('Everything flagged.', (string) $response->getBody());
    }

    public function test_without_htmx_it_goes_back_to_the_list(): void
    {
        $response = $this->act('/admin/users/2/flag');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/users', $response->getHeaderLine('Location'));
    }

    public function test_an_action_that_refuses_says_why_and_announces_nothing(): void
    {
        $admin = $this->harness(new RecordingRowAction('Grace is already flagged.'));

        $response = $admin->controller->act($admin->request('POST', '/admin/users/2/flag', $admin->frame()));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Grace is already flagged.', (string) $response->getBody());
        $admin->events->assertNothingDispatched();
    }

    public function test_a_done_action_is_announced_with_its_row(): void
    {
        $this->act('/admin/users/2/flag');
        $this->act('/admin/users/flag-all');

        [$row, $module] = $this->admin->events->dispatched();

        $this->assertInstanceOf(ActionTaken::class, $row);
        $this->assertInstanceOf(ActionTaken::class, $module);
        $this->assertSame('admin.action', $row->action());
        $this->assertSame(['module' => 'users', 'action' => 'flag', 'id' => '2'], $row->context());
        $this->assertSame(['module' => 'users', 'action' => 'flag-all', 'id' => null], $module->context());
    }

    public function test_a_screen_that_is_not_an_action_is_not_found_here(): void
    {
        $this->expectException(NotFoundException::class);

        $this->act('/admin/users/2/delete');
    }

    public function test_an_action_the_visitor_may_not_reach_does_not_run(): void
    {
        $admin = $this->harness($this->row, allowed: false);

        try {
            $admin->controller->act($admin->request('POST', '/admin/users/2/flag'));
            $this->fail('The action ran for a visitor the gate refused.');
        } catch (AuthorizationException) {
            $this->assertSame([], $this->row->ran);
        }
    }

    public function test_each_row_gets_its_buttons_where_their_when_allows(): void
    {
        $body = (string) $this->admin->controller->list($this->admin->request('GET', '/admin/users'))->getBody();

        $this->assertStringContainsString('hx-post="/admin/users/2/flag"', $body);
        $this->assertStringContainsString('hx-confirm="Flag this user?"', $body);
        $this->assertStringContainsString('>Flag</button>', $body);
        $this->assertStringContainsString('>Remove</button>', $body);
        $this->assertStringNotContainsString('/admin/users/1/flag', $body);
        $this->assertStringNotContainsString('/admin/users/1/delete', $body);
    }

    public function test_a_module_action_is_a_button_above_the_table(): void
    {
        $body = (string) $this->admin->controller->list($this->admin->request('GET', '/admin/users?q=grace'))->getBody();

        $this->assertStringContainsString('hx-post="/admin/users/flag-all?q=grace', $body);
        $this->assertStringContainsString('>Flag everyone</button>', $body);
    }

    public function test_a_row_screen_carries_the_buttons_its_row_allows(): void
    {
        $grace = (string) $this->admin->controller->show($this->admin->request('GET', '/admin/users/2'))->getBody();
        $ada = (string) $this->admin->controller->show($this->admin->request('GET', '/admin/users/1'))->getBody();

        $this->assertStringContainsString('hx-post="/admin/users/2/flag"', $grace);
        $this->assertStringContainsString('>Remove</button>', $grace);
        $this->assertStringNotContainsString('/flag', $ada);
        $this->assertStringNotContainsString('>Remove</button>', $ada);
    }

    public function test_a_hidden_button_is_no_guard_and_the_action_still_decides(): void
    {
        $this->act('/admin/users/1/flag');

        $this->assertSame(['1'], $this->row->ran);
    }

    /** @param array<string, string> $headers */
    private function act(string $path, array $headers = []): ResponseInterface
    {
        return $this->admin->controller->act($this->admin->request('POST', $path, $headers));
    }

    private function harness(RecordingRowAction $row, bool $allowed = true): AdminHarness
    {
        return new AdminHarness(
            [
                ActionUsersModule::class => new ActionUsersModule,
                CrudUserSource::class => new CrudUserSource,
                RecordingRowAction::class => $row,
                RecordingModuleAction::class => $this->module,
            ],
            [ActionUsersModule::class],
            allowed: $allowed,
        );
    }
}
