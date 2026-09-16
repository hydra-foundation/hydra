<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Events\AdminEvent;
use Hydra\Admin\Events\Exported;
use Hydra\Admin\Events\RowCreated;
use Hydra\Admin\Events\RowDeleted;
use Hydra\Admin\Events\RowUpdated;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\AdminsOnlyGate;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\Tests\Support\ExportableUsersModule;
use Hydra\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the admin announces, and when. The point of the trail is that it records
 * what happened, so the cases that matter most here are the ones where nothing
 * did: a write the source refused announces nothing, because an audit line for
 * a row that is still sitting there is worse than no line at all.
 */
#[CoversClass(AdminController::class)]
#[CoversClass(RowCreated::class)]
#[CoversClass(RowUpdated::class)]
#[CoversClass(RowDeleted::class)]
#[CoversClass(Exported::class)]
final class AdminEventsTest extends TestCase
{
    private CrudUserSource $source;
    private AdminHarness $admin;

    protected function setUp(): void
    {
        $this->source = new CrudUserSource;
        $this->admin = new AdminHarness(
            [CrudUsersModule::class => new CrudUsersModule, CrudUserSource::class => $this->source],
            [CrudUsersModule::class],
        );
    }

    public function test_a_write_announces_the_row_the_source_assigned(): void
    {
        $this->admin->controller->store(
            $this->admin->request('POST', '/admin/users/new', [], ['username' => 'linus']),
        );

        $event = $this->announced(RowCreated::class);

        $this->assertSame('users', $event->module);
        $this->assertSame('6', $event->id);
        $this->assertSame(['username' => 'linus'], $event->values);
        $this->assertSame(['module' => 'users', 'id' => '6', 'fields' => ['username']], $event->context());
    }

    public function test_an_edit_carries_the_row_as_it_stood_and_names_what_moved(): void
    {
        $this->admin->controller->update(
            $this->admin->request('POST', '/admin/users/2/edit', [], ['username' => 'hopper']),
        );

        $event = $this->announced(RowUpdated::class);

        $this->assertSame('grace', $event->before['username']);
        $this->assertSame(['username' => 'hopper'], $event->values);
        $this->assertSame(['username'], $event->changed());
    }

    /**
     * A form submits strings. A source that stored an int has not had it
     * changed by a submitted "1", and a trail that called that a change would
     * report every field of every form and so report nothing.
     */
    public function test_resubmitting_the_same_value_is_not_a_change(): void
    {
        $this->admin->controller->update(
            $this->admin->request('POST', '/admin/users/2/edit', [], ['username' => 'grace']),
        );

        $this->assertSame([], $this->announced(RowUpdated::class)->changed());
    }

    public function test_a_delete_announces_the_row_that_went(): void
    {
        $this->admin->controller->destroy($this->admin->request('POST', '/admin/users/2/delete'));

        $this->assertSame(
            ['module' => 'users', 'id' => '2'],
            $this->announced(RowDeleted::class)->context(),
        );
    }

    public function test_a_delete_carries_the_row_as_it_last_stood(): void
    {
        // Read before the delete rather than after: the row is the one thing
        // that cannot be looked up again once the screen has done its work, and
        // "row 2 went" is a far poorer record than what row 2 was.
        $this->admin->controller->destroy($this->admin->request('POST', '/admin/users/2/delete'));

        $this->assertSame('grace', $this->announced(RowDeleted::class)->before['username']);
        $this->assertNull($this->source->find('2'));
    }

    public function test_a_delete_the_source_refused_carries_nothing_because_it_announces_nothing(): void
    {
        $this->admin->controller->destroy($this->admin->request('POST', '/admin/users/1/delete'));

        $this->assertSame([], $this->admin->events->dispatched);
    }

    public function test_a_write_the_source_refused_announces_nothing(): void
    {
        // "taken" is the name CrudUserSource will not accept.
        $this->admin->controller->store(
            $this->admin->request('POST', '/admin/users/new', [], ['username' => 'taken']),
        );

        $this->assertSame([], $this->admin->events->dispatched);
    }

    public function test_a_delete_the_source_refused_announces_nothing(): void
    {
        // The first user cannot be deleted, and is still there afterwards.
        $this->admin->controller->destroy($this->admin->request('POST', '/admin/users/1/delete'));

        $this->assertNotNull($this->source->find('1'));
        $this->assertSame([], $this->admin->events->dispatched);
    }

    public function test_a_submission_that_fails_validation_announces_nothing(): void
    {
        $this->admin->controller->store(
            $this->admin->request('POST', '/admin/users/new', [], ['username' => '']),
        );

        $this->assertSame([], $this->admin->events->dispatched);
    }

    public function test_reading_a_screen_announces_nothing(): void
    {
        $this->admin->controller->list($this->admin->request('GET', '/admin/users'));
        $this->admin->controller->show($this->admin->request('GET', '/admin/users/2'));
        $this->admin->controller->edit($this->admin->request('GET', '/admin/users/2/edit'));

        $this->assertSame([], $this->admin->events->dispatched);
    }

    /**
     * The event this whole trail was added for: one GET takes the whole table
     * and the URL alone does not say how much left with it.
     */
    public function test_an_export_announces_the_view_and_how_much_of_it_went(): void
    {
        $admin = new AdminHarness(
            [ExportableUsersModule::class => new ExportableUsersModule, CrudUserSource::class => $this->source],
            [ExportableUsersModule::class],
        );

        $admin->controller->export($admin->request('GET', '/admin/users/export?q=a'));

        $event = $this->announced(Exported::class, $admin);

        $this->assertSame('a', $event->criteria->search);
        $this->assertSame(4, $event->rows);
        $this->assertSame(
            ['module' => 'users', 'rows' => 4, 'view' => ['q' => 'a', 'sort' => 'id', 'dir' => 'asc']],
            $event->context(),
        );
    }

    /**
     * The row count is the rows that went into the file, not the page size the
     * module lists at: the module pages two at a time and the file holds five.
     */
    public function test_the_count_is_of_the_file_and_not_of_a_page(): void
    {
        $admin = new AdminHarness(
            [ExportableUsersModule::class => new ExportableUsersModule, CrudUserSource::class => $this->source],
            [ExportableUsersModule::class],
        );

        $admin->controller->export($admin->request('GET', '/admin/users/export'));

        $this->assertSame(5, $this->announced(Exported::class, $admin)->rows);
    }

    /**
     * One registration against the base class hears everything, present and
     * future, because the listener provider matches an event's subtypes.
     */
    public function test_every_admin_event_is_one_a_single_listener_can_hear(): void
    {
        $this->admin->controller->store(
            $this->admin->request('POST', '/admin/users/new', [], ['username' => 'linus']),
        );
        $this->admin->controller->update(
            $this->admin->request('POST', '/admin/users/2/edit', [], ['username' => 'hopper']),
        );
        $this->admin->controller->destroy($this->admin->request('POST', '/admin/users/2/delete'));

        // Anything the admin dispatched that was not an AdminEvent names its own
        // type here instead of an action, and fails the comparison.
        $actions = array_map(
            static fn (object $event): string => $event instanceof AdminEvent
                ? $event->action()
                : get_debug_type($event),
            $this->admin->events->dispatched,
        );

        $this->assertSame(['admin.row_created', 'admin.row_updated', 'admin.row_deleted'], $actions);
    }

    /**
     * The dispatcher is optional. An application that bound none gets an admin
     * that writes rows and says nothing about it, rather than one that fails.
     */
    public function test_an_admin_with_no_dispatcher_still_writes(): void
    {
        $controller = new AdminController(
            $this->admin->registry,
            $this->admin->chrome,
            $this->admin->renderer,
            new AdminsOnlyGate(true),
            $this->admin->responder,
            new Validator,
        );

        $controller->store($this->admin->request('POST', '/admin/users/new', [], ['username' => 'linus']));

        $this->assertSame('linus', $this->source->find('6')['username'] ?? null);
    }

    /**
     * The first thing the admin announced, which has to be of the type the
     * case is about.
     *
     * @template T of AdminEvent
     * @param class-string<T> $type
     * @return T
     */
    private function announced(string $type, ?AdminHarness $admin = null): AdminEvent
    {
        $event = ($admin ?? $this->admin)->events->dispatched[0] ?? null;

        if (!$event instanceof $type) {
            $this->fail(sprintf(
                'Expected the admin to announce a %s; it announced %s.',
                $type,
                get_debug_type($event),
            ));
        }

        return $event;
    }
}
