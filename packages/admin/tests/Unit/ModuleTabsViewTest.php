<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Renderer;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\ArrayRowSource;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\DeclaredModule;
use Hydra\Admin\ViewModels\ScreenViewModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The strip as a visitor gets it: links, not a script, so each tab is a URL the
 * browser keeps in its history, drawn under the heading on every screen of the
 * family, and absent everywhere else.
 */
#[CoversClass(Renderer::class)]
#[CoversClass(ScreenViewModel::class)]
final class ModuleTabsViewTest extends TestCase
{
    public function test_a_tab_draws_the_strip_under_its_heading(): void
    {
        $html = $this->get('/admin/failed-jobs');

        $this->assertSame(1, substr_count($html, 'class="admin-tabs"'));
        $this->assertMatchesRegularExpression('~class="admin-tabs"[^>]*aria-label="Jobs"~', $html);
        $this->assertLessThan(strpos($html, 'class="admin-tabs"'), strpos($html, '<h1'));
        $this->assertMatchesRegularExpression(
            '~<a class="admin-tab"\s+href="/admin/jobs"[^>]*>Jobs</a>~',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '~<a class="admin-tab active"\s+href="/admin/failed-jobs"[^>]*aria-current="page"[^>]*>Failed jobs</a>~',
            $html,
        );
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function test_each_tab_swaps_the_frame_and_keeps_its_url(): void
    {
        $html = $this->get('/admin/jobs');

        $this->assertMatchesRegularExpression(
            '~<a class="admin-tab"\s+href="/admin/failed-jobs"\s+hx-nonce="[^"]+"\s+hx-get="/admin/failed-jobs"\s+hx-target="#admin-frame"\s+hx-push-url="true"~',
            $html,
        );
    }

    public function test_the_frame_htmx_swaps_carries_the_strip_too(): void
    {
        $html = $this->get('/admin/failed-jobs', frame: true);

        $this->assertStringContainsString('class="admin-tabs"', $html);
        $this->assertStringNotContainsString('<html', $html);
    }

    public function test_a_screen_for_one_row_keeps_the_strip(): void
    {
        $html = $this->get('/admin/failed-jobs/1');

        $this->assertStringContainsString('class="admin-tabs"', $html);
        $this->assertMatchesRegularExpression('~class="admin-tab active"\s+href="/admin/failed-jobs"~', $html);
    }

    public function test_a_module_without_tabs_draws_no_strip(): void
    {
        $this->assertStringNotContainsString('admin-tabs', $this->get('/admin/users'));
    }

    private function get(string $path, bool $frame = false): string
    {
        $admin = new AdminHarness(
            [
                'jobs' => new DeclaredModule(Definition::make('jobs')->group('Queue')->source(ArraySource::class)->fields(Field::id())),
                'failed-jobs' => new DeclaredModule(
                    Definition::make('failed-jobs')
                        ->title('Failed jobs')
                        ->tabOf('jobs')
                        ->source(ArrayRowSource::class)
                        ->fields(Field::id())
                        ->screens(ShowScreen::make()),
                ),
                'users' => new DeclaredModule(Definition::make('users')->source(ArraySource::class)->fields(Field::id())),
                ArraySource::class => new ArraySource,
                ArrayRowSource::class => new ArrayRowSource,
            ],
            ['jobs', 'failed-jobs', 'users'],
        );
        $request = $admin->request('GET', $path, $frame ? $admin->frame() : []);
        $response = str_ends_with($path, '/1')
            ? $admin->controller->show($request)
            : $admin->controller->list($request);

        return (string) $response->getBody();
    }
}
