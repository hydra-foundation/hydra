<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Blueprint;
use Hydra\Admin\Contracts\ModuleActionInterface;
use Hydra\Admin\Contracts\RowActionInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\ModuleScanner;
use Hydra\Admin\Screens\ActionScreen;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\RecordingModuleAction;
use Hydra\Admin\Tests\Support\RecordingRowAction;
use Hydra\Core\Testing\FakeContainer;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionScreen::class)]
#[CoversClass(Definition::class)]
final class ActionScreenTest extends TestCase
{
    public function test_a_row_action_is_a_post_under_the_row(): void
    {
        $screen = ActionScreen::row('retry')->runs(RecordingRowAction::class);

        $this->assertSame('retry', $screen->name());
        $this->assertSame('POST', $screen->method());
        $this->assertSame('{id}/retry', $screen->path());
        $this->assertSame([AdminController::class, 'act'], $screen->handler());
        $this->assertTrue($screen->isRowScoped());
        $this->assertSame(RecordingRowAction::class, $screen->action());
    }

    public function test_a_module_action_is_a_post_at_the_module(): void
    {
        $screen = ActionScreen::module('retry-all')->runs(RecordingModuleAction::class);

        $this->assertSame('retry-all', $screen->path());
        $this->assertSame('POST', $screen->method());
        $this->assertFalse($screen->isRowScoped());
    }

    public function test_it_is_labelled_from_its_name_until_told_otherwise(): void
    {
        $screen = ActionScreen::module('retry-all');

        $this->assertSame('Retry all', $screen->label());
        $this->assertSame('Again', $screen->labelled('Again')->label());
    }

    public function test_it_asks_nothing_until_given_a_prompt(): void
    {
        $screen = ActionScreen::row('retry');

        $this->assertNull($screen->prompt());
        $this->assertSame('Sure?', $screen->confirm('Sure?')->prompt());
    }

    public function test_a_screen_ability_overrides_the_modules(): void
    {
        $this->assertNull(ActionScreen::row('retry')->ability());
        $this->assertSame('RetryJobs', ActionScreen::row('retry')->requires('RetryJobs')->ability());
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'a slash' => ['retry/all'];
        yield 'upper case' => ['Retry'];
        yield 'a placeholder' => ['{id}'];
    }

    #[DataProvider('badNames')]
    public function test_a_name_that_cannot_be_a_path_segment_is_refused(string $name): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('lower-case letters, digits and dashes');

        ActionScreen::row($name);
    }

    #[DataProvider('reservedNames')]
    public function test_a_name_the_admin_gives_its_own_screens_is_refused(string $name): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("An action cannot be named \"{$name}\"");

        ActionScreen::module($name);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedNames(): iterable
    {
        yield 'the list' => ['list'];
        yield 'the link counts' => ['counts'];
    }

    public function test_a_row_action_must_run_a_row_action(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(RowActionInterface::class);

        ActionScreen::row('retry')->runs(RecordingModuleAction::class);
    }

    public function test_a_module_action_must_run_a_module_action(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(ModuleActionInterface::class);

        ActionScreen::module('retry-all')->runs(RecordingRowAction::class);
    }

    public function test_an_action_with_nothing_to_run_does_not_compile(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Admin module "jobs" declares an action "retry" that runs nothing.');

        $this->define(ActionScreen::row('retry'))->compile();
    }

    /** @return iterable<string, array{ActionScreen}> */
    public static function takenNames(): iterable
    {
        yield 'a built-in screen' => [ActionScreen::module('delete')->runs(RecordingModuleAction::class)];
        yield 'another action' => [ActionScreen::module('retry')->runs(RecordingModuleAction::class)];
    }

    #[DataProvider('takenNames')]
    public function test_an_action_named_like_another_screen_does_not_compile(ActionScreen $clash): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Admin module \"jobs\" declares an action \"{$clash->name()}\" but another screen already has that name.");

        $this->define(
            DeleteScreen::make(),
            ActionScreen::row('retry')->runs(RecordingRowAction::class),
            $clash,
        )->compile();
    }

    public function test_both_scopes_compile_to_posts_and_resolve_back(): void
    {
        $blueprint = $this->blueprint();
        $routes = (new ModuleScanner)->scan([$blueprint], '/admin');
        $posts = array_column(
            array_filter($routes, static fn (array $route): bool => $route['method'] === 'POST'),
            'path',
            'name',
        );
        $registry = new ModuleRegistry(new FakeContainer([]), []);

        $this->assertSame('/admin/jobs/{id}/retry', $posts['jobs.retry']);
        $this->assertSame('/admin/jobs/retry-all', $posts['jobs.retry-all']);
        $this->assertSame('retry', $registry->screenAt($blueprint, '/admin/jobs/7/retry', 'POST')?->name());
        $this->assertSame('retry-all', $registry->screenAt($blueprint, '/admin/jobs/retry-all', 'POST')?->name());
        $this->assertSame('delete', $registry->screenAt($blueprint, '/admin/jobs/7/delete', 'POST')?->name());
        $this->assertNull($registry->screenAt($blueprint, '/admin/jobs/7/retry', 'GET'));
    }

    private function blueprint(): Blueprint
    {
        return $this->define(
            DeleteScreen::make(),
            ActionScreen::row('retry')->runs(RecordingRowAction::class),
            ActionScreen::module('retry-all')->runs(RecordingModuleAction::class),
        )->compile();
    }

    private function define(ActionScreen|DeleteScreen ...$screens): Definition
    {
        return Definition::make('jobs')
            ->source(new ArraySource)
            ->fields(Field::id())
            ->screens(...$screens);
    }
}
