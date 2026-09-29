<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Contracts\CreateSourceInterface;
use Hydra\Admin\Contracts\UpdateSourceInterface;
use Hydra\Admin\Blueprint;
use Hydra\Admin\Criteria;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Admin\Tests\Support\ArrayWritableSource;
use Hydra\Admin\Tests\Support\ArrayRowSource;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\DeclaredModule;
use Hydra\Admin\Tests\Support\EditableUsersModule;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Hydra\Admin\Tests\Support\UsersModule;
use Hydra\Admin\Tests\Support\ViewableUsersModule;
use PHPUnit\Framework\TestCase;

/**
 * Mapping a request back to the screen that answers it, and the capability
 * checks in front of that: a screen cannot be served by a source that lacks the
 * operation it needs, and one write capability never implies another.
 */
#[CoversClass(ModuleRegistry::class)]
final class ModuleRegistryTest extends TestCase
{
    public function test_it_maps_a_request_path_back_to_its_module(): void
    {
        $registry = $this->registry();

        $this->assertSame('users', $registry->fromPath('/admin/users')?->slug);
        $this->assertSame('users', $registry->fromPath('/admin/users/42/edit')?->slug);
    }

    public function test_it_does_not_claim_paths_outside_the_prefix(): void
    {
        $registry = $this->registry();

        $this->assertNull($registry->fromPath('/admin'));
        $this->assertNull($registry->fromPath('/users'));
        $this->assertNull($registry->fromPath('/admin/unknown'));
    }

    public function test_a_service_id_source_is_resolved_at_request_time(): void
    {
        $registry = $this->registry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        $this->assertSame(2, $registry->source($blueprint)->page(new Criteria)->total);
    }

    public function test_a_placeholder_screen_resolves_a_concrete_path(): void
    {
        $registry = $this->editableRegistry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        $this->assertSame('edit', $registry->screenAt($blueprint, '/admin/users/42/edit', 'GET')?->name());
        $this->assertSame('list', $registry->screenAt($blueprint, '/admin/users', 'GET')?->name());
    }

    public function test_a_literal_path_wins_over_a_placeholder(): void
    {
        $registry = $this->editableRegistry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        $this->assertSame('new', $registry->screenAt($blueprint, '/admin/users/new', 'GET')?->name());
    }

    public function test_two_screens_may_share_a_path_under_different_methods(): void
    {
        $blueprint = Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::id())
            ->screens(ShowScreen::make(), DeleteScreen::make('{id}'))
            ->compile();

        $registry = new ModuleRegistry(new FakeContainer([]), []);

        $this->assertSame('show', $registry->screenAt($blueprint, '/admin/users/42', 'GET')?->name());
        $this->assertSame('delete', $registry->screenAt($blueprint, '/admin/users/42', 'POST')?->name());
    }

    public function test_a_submittable_screen_answers_the_post_beside_it(): void
    {
        $registry = $this->editableRegistry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        // The edit screen is a GET, and the POST the scanner emits at the same
        // path is still the edit screen.
        $this->assertSame('edit', $registry->screenAt($blueprint, '/admin/users/42/edit', 'POST')?->name());
    }

    public function test_a_screen_does_not_answer_a_method_it_never_declared(): void
    {
        $registry = $this->editableRegistry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        $this->assertNull($registry->screenAt($blueprint, '/admin/users', 'POST'));
        $this->assertNull($registry->screenAt($blueprint, '/admin/users/42/edit', 'DELETE'));
    }

    public function test_a_path_that_matches_no_screen_stays_unresolved(): void
    {
        $registry = $this->editableRegistry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        $this->assertNull($registry->screenAt($blueprint, '/admin/users/42/edit/extra', 'GET'));
        $this->assertNull($registry->screenAt($blueprint, '/admin/users/42/delete', 'POST'));
    }

    public function test_a_read_only_source_cannot_serve_an_edit_screen(): void
    {
        $registry = $this->registry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement');

        $registry->updateSource($blueprint);
    }

    public function test_a_source_that_reads_one_row_serves_a_show_screen_without_being_writable(): void
    {
        $registry = $this->viewableRegistry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        $this->assertSame('ada', $registry->rowSource($blueprint)->find('1')['username'] ?? null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement');

        $registry->updateSource($blueprint);
    }

    public function test_a_source_that_updates_is_not_thereby_allowed_to_create(): void
    {
        $registry = $this->editableRegistry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        $this->assertInstanceOf(UpdateSourceInterface::class, $registry->updateSource($blueprint));
        $this->assertInstanceOf(CreateSourceInterface::class, $registry->createSource($blueprint));

        $readOnly = $this->registry()->find('users');
        $this->assertNotNull($readOnly);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('create screen');

        $this->registry()->createSource($readOnly);
    }

    public function test_a_source_that_writes_is_not_thereby_allowed_to_delete(): void
    {
        $registry = $this->editableRegistry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delete screen');

        $registry->deleteSource($blueprint);
    }

    public function test_a_source_that_reads_only_pages_cannot_serve_a_screen_for_one_row(): void
    {
        $registry = $this->registry();
        $blueprint = $registry->find('users');

        $this->assertNotNull($blueprint);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('screen for one row');

        $registry->rowSource($blueprint);
    }

    public function test_a_module_without_tabs_is_a_family_of_one(): void
    {
        $registry = $this->family(['jobs' => null]);

        $this->assertSame(['jobs'], $this->slugs($registry->family($this->find($registry, 'jobs'))));
    }

    /**
     * The parent leads whichever member is asked about, and its tabs follow in
     * the order the application registered them, not the order they were
     * found in, so the strip reads the same on every screen of the family.
     */
    public function test_a_family_is_the_parent_then_its_tabs_in_registration_order(): void
    {
        $registry = $this->family([
            'failed-jobs' => 'jobs',
            'users' => null,
            'jobs' => null,
            'batches' => 'jobs',
        ]);

        foreach (['jobs', 'failed-jobs', 'batches'] as $member) {
            $this->assertSame(
                ['jobs', 'failed-jobs', 'batches'],
                $this->slugs($registry->family($this->find($registry, $member))),
                "asked from {$member}",
            );
        }

        $this->assertSame(['users'], $this->slugs($registry->family($this->find($registry, 'users'))));
    }

    public function test_a_tab_of_a_module_that_is_not_registered_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Admin module "failed-jobs" is a tab of "job", which is not registered. Register it, or fix the slug in tabOf().'
        );

        $this->family(['jobs' => null, 'failed-jobs' => 'job'])->all();
    }

    /**
     * One level only. A tab of a tab would need a strip inside a strip, and
     * the fix is always to point at the module the whole family sits behind.
     */
    public function test_a_tab_of_a_tab_is_refused_with_the_slug_to_point_at(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Admin module "retries" is a tab of "failed-jobs", which is itself a tab of "jobs". Point tabOf() at "jobs".'
        );

        $this->family(['jobs' => null, 'failed-jobs' => 'jobs', 'retries' => 'failed-jobs'])->all();
    }

    public function test_a_tab_may_be_registered_ahead_of_its_parent(): void
    {
        $registry = $this->family(['failed-jobs' => 'jobs', 'jobs' => null]);

        $this->assertSame(['failed-jobs', 'jobs'], array_keys($registry->all()));
    }

    /**
     * Each module is a DeclaredModule under its own slug as the service id,
     * tabbed to the parent given, or to nothing for null.
     *
     * @param array<string, ?string> $modules slug => parent
     */
    private function family(array $modules): ModuleRegistry
    {
        $services = [];

        foreach ($modules as $slug => $parent) {
            $definition = Definition::make($slug)->source(new ArraySource)->fields(Field::id());
            $services[$slug] = new DeclaredModule($parent === null ? $definition : $definition->tabOf($parent));
        }

        return new ModuleRegistry(new FakeContainer($services), array_keys($services));
    }

    private function find(ModuleRegistry $registry, string $slug): Blueprint
    {
        $blueprint = $registry->find($slug);
        $this->assertNotNull($blueprint);

        return $blueprint;
    }

    /**
     * @param list<Blueprint> $blueprints
     * @return list<string>
     */
    private function slugs(array $blueprints): array
    {
        return array_map(static fn (Blueprint $blueprint): string => $blueprint->slug, $blueprints);
    }

    private function viewableRegistry(): ModuleRegistry
    {
        return new ModuleRegistry(
            new FakeContainer([
                ViewableUsersModule::class => new ViewableUsersModule,
                ArrayRowSource::class => new ArrayRowSource,
            ]),
            [ViewableUsersModule::class],
        );
    }

    private function editableRegistry(): ModuleRegistry
    {
        return new ModuleRegistry(
            new FakeContainer([
                EditableUsersModule::class => new EditableUsersModule,
                ArrayWritableSource::class => new ArrayWritableSource,
            ]),
            [EditableUsersModule::class],
        );
    }

    private function registry(): ModuleRegistry
    {
        return new ModuleRegistry(
            new FakeContainer([
                UsersModule::class => new UsersModule,
                ArraySource::class => new ArraySource([['id' => 1], ['id' => 2]]),
            ]),
            [UsersModule::class],
        );
    }
}
