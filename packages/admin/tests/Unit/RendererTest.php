<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Criteria;
use Hydra\Admin\Notice;
use Hydra\Admin\Renderer;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\ViewModels\ListViewModel;
use Hydra\Http\Status;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;

/**
 * One screen at three depths. What separates them is the htmx target, and what
 * each depth owes the client is different: a whole page, a frame carrying a new
 * title and an out-of-band sidebar, or a bare body.
 */
final class RendererTest extends TestCase
{
    private AdminHarness $admin;

    protected function setUp(): void
    {
        $this->admin = new AdminHarness(
            [CrudUsersModule::class => new CrudUsersModule, CrudUserSource::class => new CrudUserSource],
            [CrudUsersModule::class],
        );
    }

    public function test_a_plain_request_gets_the_whole_page(): void
    {
        $body = $this->render();

        $this->assertStringContainsString('<!doctype html>', $body);
        $this->assertStringContainsString('id="' . Renderer::FRAME . '"', $body);
        $this->assertStringContainsString('id="' . Renderer::BODY . '"', $body);
    }

    public function test_a_frame_swap_drops_the_page_but_keeps_the_body_inside_it(): void
    {
        $body = $this->render($this->admin->frame());

        $this->assertStringNotContainsString('<!doctype html>', $body);
        $this->assertStringContainsString('id="' . Renderer::BODY . '"', $body);
    }

    public function test_a_frame_swap_carries_the_title_and_an_out_of_band_sidebar(): void
    {
        // The sidebar sits outside the region being swapped, so it can only be
        // updated out of band; the title is lifted out of the head block.
        $body = $this->render($this->admin->frame());

        $this->assertStringContainsString('<title>Users · Admin</title>', $body);
        $this->assertStringContainsString('hx-swap-oob="true"', $body);
    }

    public function test_a_body_swap_is_the_table_and_nothing_around_it(): void
    {
        $body = $this->render($this->admin->body());

        $this->assertStringNotContainsString('<!doctype html>', $body);
        $this->assertStringNotContainsString('hx-swap-oob="true"', $body);
        $this->assertStringNotContainsString('id="' . Renderer::BODY . '"', $body);
        $this->assertStringContainsString('<table', $body);
    }

    public function test_an_htmx_request_at_an_unknown_target_is_answered_in_full(): void
    {
        // Nothing says the target is one of ours: a page swapping the admin
        // into itself asks for a page, not a fragment.
        $body = $this->render(['HX-Request' => 'true', 'HX-Target' => 'div#somewhere-else']);

        $this->assertStringContainsString('<!doctype html>', $body);
    }

    public function test_the_status_the_caller_asked_for_survives_the_depth(): void
    {
        foreach ([[], $this->admin->frame(), $this->admin->body()] as $headers) {
            $this->assertSame(422, $this->respond($headers, Status::UnprocessableEntity)->getStatusCode());
        }
    }

    public function test_a_notice_is_rendered_with_the_role_that_announces_it(): void
    {
        $body = (string) $this->admin->renderer->screen(
            $this->admin->request('GET', '/admin/users', $this->admin->frame()),
            $this->admin->chrome->module($this->admin->registry->find('users'), notice: Notice::failure('No.')),
            'admin/partials/table',
            $this->data(),
        )->getBody();

        $this->assertStringContainsString('role="alert"', $body);
        $this->assertStringContainsString('No.', $body);
    }

    /** @param array<string, string> $headers */
    private function render(array $headers = []): string
    {
        return (string) $this->respond($headers)->getBody();
    }

    /** @param array<string, string> $headers */
    private function respond(array $headers = [], int|Status $status = Status::Ok): ResponseInterface
    {
        $blueprint = $this->admin->registry->find('users');

        return $this->admin->renderer->screen(
            $this->admin->request('GET', '/admin/users', $headers),
            $this->admin->chrome->module($blueprint),
            'admin/partials/table',
            $this->data(),
            status: $status,
        );
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $blueprint = $this->admin->registry->find('users');

        return ['vm' => new ListViewModel(
            $blueprint,
            $this->admin->registry->source($blueprint)->page(Criteria::defaults($blueprint)),
            '/admin',
        )];
    }
}
