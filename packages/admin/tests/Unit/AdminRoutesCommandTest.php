<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Console\ExitCode;
use Hydra\Console\ArrayInput;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Admin\Console\AdminRoutesCommand;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Tests\Support\ArrayContainer;
use Hydra\Admin\Tests\Support\ArrayWritableSource;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\Tests\Support\EditableUsersModule;
use Hydra\Admin\Tests\Support\LandingModule;
use Hydra\Admin\Tests\Support\TypoModule;
use Hydra\Admin\Tests\Support\TypoWidgetModule;
use Hydra\Admin\Tests\Support\WidgetDashboardModule;
use Hydra\Admin\AdminServiceProvider;
use Hydra\Http\CspNonce;
use Hydra\View\PhpView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The receipt for everything the admin generated on the application's behalf.
 * It is the only place a developer sees the compiled routes, so it has to name
 * all of them and say which ability each one answers to.
 */
#[CoversClass(AdminRoutesCommand::class)]
final class AdminRoutesCommandTest extends TestCase
{
    private ExitCode $code;

    public function test_it_lists_every_route_the_modules_compiled_to(): void
    {
        $output = $this->display(new CrudUsersModule, CrudUserSource::class, new CrudUserSource);

        foreach (['/admin/users', '/admin/users/new', '/admin/users/{id}', '/admin/users/{id}/edit', '/admin/users/{id}/delete'] as $path) {
            $this->assertStringContainsString($path, $output);
        }

        $this->assertStringContainsString('users.list', $output);
        $this->assertStringContainsString('users.delete', $output);
    }

    public function test_a_submittable_screen_is_shown_with_the_post_beside_it(): void
    {
        $output = $this->display(new CrudUsersModule, CrudUserSource::class, new CrudUserSource);

        $this->assertStringContainsString('users.edit.submit', $output);
        $this->assertStringContainsString('users.create.submit', $output);
    }

    public function test_each_route_is_shown_with_the_ability_it_answers_to(): void
    {
        // Including the POST beside a submittable screen, which answers at the
        // same screen and so answers to the same ability.
        $output = $this->display(new CrudUsersModule, CrudUserSource::class, new CrudUserSource);

        $this->assertSame(
            substr_count($output, 'users.'),
            substr_count($output, 'ManageUsers'),
            'every generated route should be listed with an ability',
        );
    }

    public function test_a_route_no_ability_guards_says_so_rather_than_leaving_a_gap(): void
    {
        $output = $this->display(new EditableUsersModule, ArrayWritableSource::class, new ArrayWritableSource);

        $this->assertStringContainsString('—', $output);
    }

    public function test_a_page_screen_is_listed_with_the_template_it_renders(): void
    {
        $output = $this->routes(new LandingModule);

        $this->assertStringContainsString('admin/dashboard', implode("\n", $output->lines()));
        $this->assertSame(ExitCode::Success, $this->code);
    }

    public function test_a_template_nobody_ships_is_reported_rather_than_waiting_for_a_visitor(): void
    {
        // It routes, it gates, it breadcrumbs, and it 500s when followed. The
        // receipt is where that has to surface, because nothing else looks.
        $output = $this->routes(new TypoModule);

        $this->assertStringContainsString('admin/reprots', implode("\n", $output->lines()));
        $this->assertStringContainsString('No template found', implode("\n", $output->lines()));
        $this->assertSame(ExitCode::Failure, $this->code);
    }

    public function test_without_a_view_it_still_lists_the_routes_it_cannot_check(): void
    {
        // The check needs a view; the receipt does not, and is worth printing
        // either way.
        $output = new FakeOutput;
        $this->code = (new AdminRoutesCommand($this->registry(new TypoModule)))->execute(new ArrayInput, $output);

        $this->assertStringContainsString('admin/reprots', implode("\n", $output->lines()));
        $this->assertStringNotContainsString('No template found', implode("\n", $output->lines()));
        $this->assertSame(ExitCode::Success, $this->code);
    }

    public function test_a_screen_the_admin_renders_itself_claims_no_template(): void
    {
        // A table and a form come from the package's own templates, which the
        // package's own tests cover; only a page screen names one.
        $output = $this->display(new CrudUsersModule, CrudUserSource::class, new CrudUserSource);

        $this->assertSame(
            substr_count($output, 'users.'),
            substr_count($output, '—'),
            'no generated screen should claim a template of its own',
        );
    }

    public function test_a_widget_naming_a_template_nobody_ships_is_reported(): void
    {
        // Nothing else looks: compile() has no view to ask, and the widget
        // route renders whichever card the URL names, so the first sign of it
        // otherwise is one dead card on an otherwise working dashboard.
        $output = $this->routes(new TypoWidgetModule);

        $this->assertSame(ExitCode::Failure, $this->code);
        $this->assertStringContainsString('admin/widgets/cuonts', implode("\n", $output->lines()));
    }

    public function test_the_widget_route_is_shown_with_the_cards_it_serves(): void
    {
        $output = $this->routes(new TypoWidgetModule);

        $this->assertStringContainsString('/w/{widget}', implode("\n", $output->lines()));
        $this->assertStringContainsString('1 widget', implode("\n", $output->lines()));
    }

    public function test_a_dashboard_whose_cards_all_exist_passes(): void
    {
        // The count in the widget row is a tally, not a template. Asking the
        // view for "5 widgets" is a question it can only answer no to, which
        // failed every dashboard that was in fact complete.
        $output = $this->routes(new WidgetDashboardModule);

        $this->assertSame(ExitCode::Success, $this->code);
        $this->assertStringContainsString('5 widgets', implode("\n", $output->lines()));
        $this->assertStringNotContainsString('No template found', implode("\n", $output->lines()));
    }

    /** Runs the route listing for one module and keeps what it said. */
    private function routes(object $module): FakeOutput
    {
        $output = new FakeOutput;

        $this->code = (new AdminRoutesCommand(
            $this->registry($module),
            new PhpView(
                dirname(__DIR__) . '/views',
                new CspNonce,
                fallbacks: [AdminServiceProvider::views()],
            ),
        ))->execute(new ArrayInput, $output);

        return $output;
    }

    private function registry(object $module): ModuleRegistry
    {
        return new ModuleRegistry(new ArrayContainer([$module::class => $module]), [$module::class]);
    }

    private function display(object $module, string $sourceId, object $source): string
    {
        $registry = new ModuleRegistry(
            new ArrayContainer([$module::class => $module, $sourceId => $source]),
            [$module::class],
        );

        $output = new FakeOutput;
        $this->code = (new AdminRoutesCommand($registry))->execute(new ArrayInput, $output);

        return implode("\n", $output->lines());
    }
}
