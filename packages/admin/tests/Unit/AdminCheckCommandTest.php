<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Console\ExitCode;
use Hydra\Console\ArrayInput;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Admin\Console\AdminCheckCommand;
use Hydra\Admin\ModuleRegistry;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\DescribedSource;
use Hydra\Admin\Tests\Support\DescribedUsersModule;
use Hydra\Admin\Tests\Support\LandingModule;
use Hydra\Admin\Tests\Support\UsersModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The one pairing nothing else in the toolchain can see: a module's field names
 * against the column list of the source it reads. Every case here is a bug that
 * ships silently — the screen renders, the request succeeds, and the answer is
 * wrong — so the command failing loudly is the whole behaviour under test.
 */
#[CoversClass(AdminCheckCommand::class)]
final class AdminCheckCommandTest extends TestCase
{
    private ExitCode $code;

    public function test_a_module_naming_only_columns_its_source_offers_passes(): void
    {
        $output = $this->check(new DescribedSource);

        $this->assertSame(ExitCode::Success, $this->code);
        $this->assertStringContainsString('users', implode("\n", $output->lines()));
        $this->assertStringContainsString('ok', implode("\n", $output->lines()));
    }

    public function test_a_field_over_a_column_the_source_does_not_read_is_reported(): void
    {
        $output = $this->check(new DescribedSource(columns: ['id', 'username', 'role']));

        $this->assertSame(ExitCode::Failure, $this->code);
        $this->assertStringContainsString('note', implode("\n", $output->lines()));
        $this->assertStringContainsString('is not a column the source reads', implode("\n", $output->lines()));
    }

    public function test_a_column_the_source_does_not_read_is_reported_once_and_not_four_times(): void
    {
        // Sorting, searching and filtering by a column the source never selects
        // are all consequences of the same mistake, and saying so four times
        // buries the line that names it.
        $output = $this->check(new DescribedSource(columns: ['id', 'role', 'note']));

        $this->assertSame(1, substr_count(implode("\n", $output->lines()), 'username'));
    }

    public function test_a_sortable_field_the_source_will_not_order_by_is_reported(): void
    {
        $output = $this->check(new DescribedSource(sortable: ['id']));

        $this->assertSame(ExitCode::Failure, $this->code);
        $this->assertStringContainsString('is sortable, but the source does not sort by it', implode("\n", $output->lines()));
    }

    public function test_a_searchable_field_the_source_never_looks_in_is_reported(): void
    {
        $output = $this->check(new DescribedSource(searchable: []));

        $this->assertSame(ExitCode::Failure, $this->code);
        $this->assertStringContainsString('is searchable, but the source does not search it', implode("\n", $output->lines()));
    }

    public function test_a_filterable_field_the_source_never_narrows_on_is_reported(): void
    {
        // The reason the command exists: the filter renders, submits, narrows
        // nothing, and the screen answers with the whole table.
        $output = $this->check(new DescribedSource(filterable: []));

        $this->assertSame(ExitCode::Failure, $this->code);
        $this->assertStringContainsString('is filterable, but the source does not filter by it', implode("\n", $output->lines()));
    }

    public function test_a_filter_link_the_source_will_not_narrow_on_is_reported(): void
    {
        // Worse than the toolbar case above: a link reading "Admins 4,113" looks
        // like an answer, and what it leads to is the whole table.
        $output = $this->check(new DescribedSource(filterable: ['note']));

        $this->assertSame(ExitCode::Failure, $this->code);
        $this->assertStringContainsString('filter link "Admins" pins "role"', implode("\n", $output->lines()));
    }

    public function test_a_default_sort_the_source_will_not_honour_is_reported(): void
    {
        // Wrong on the very first request, before a visitor has touched a header.
        $output = $this->check(new DescribedSource(sortable: ['username'], defaultSort: 'username'));

        $this->assertSame(ExitCode::Failure, $this->code);
        $this->assertStringContainsString('defaultSort is "id"', implode("\n", $output->lines()));
        $this->assertStringContainsString('every list starts on "username" instead', implode("\n", $output->lines()));
    }

    public function test_a_source_offering_more_than_the_module_names_is_not_a_problem(): void
    {
        // How one source stays shared between two modules showing different
        // halves of a table.
        $output = $this->check(new DescribedSource(
            columns: ['id', 'username', 'role', 'note', 'email'],
            sortable: ['id', 'username', 'email'],
            searchable: ['username', 'email'],
            filterable: ['role', 'email'],
        ));

        $this->assertSame(ExitCode::Success, $this->code);
    }

    public function test_a_source_that_cannot_describe_itself_is_unchecked_rather_than_passing(): void
    {
        // A check that quietly finds nothing is the same as no check at all, so
        // the one thing it must not do is print a tick.
        $registry = new ModuleRegistry(
            new FakeContainer([UsersModule::class => new UsersModule, ArraySource::class => new ArraySource]),
            [UsersModule::class],
        );

        $output = new FakeOutput;
        $this->code = (new AdminCheckCommand($registry))->execute(new ArrayInput, $output);

        $this->assertSame(ExitCode::Success, $this->code);
        $this->assertStringContainsString('not describable', implode("\n", $output->lines()));
        $this->assertStringContainsString('DescribesColumnsInterface', implode("\n", $output->lines()));
    }

    public function test_a_module_with_no_source_has_nothing_to_check(): void
    {
        $registry = new ModuleRegistry(
            new FakeContainer([LandingModule::class => new LandingModule]),
            [LandingModule::class],
        );

        $output = new FakeOutput;
        $this->code = (new AdminCheckCommand($registry))->execute(new ArrayInput, $output);

        $this->assertSame(ExitCode::Success, $this->code);
        $this->assertStringContainsString('home', implode("\n", $output->lines()));
    }

    /** Runs the check over one source and keeps what it said. */
    private function check(DescribedSource $source): FakeOutput
    {
        $registry = new ModuleRegistry(
            new FakeContainer([
                DescribedUsersModule::class => new DescribedUsersModule,
                DescribedSource::class => $source,
            ]),
            [DescribedUsersModule::class],
        );

        $output = new FakeOutput;
        $this->code = (new AdminCheckCommand($registry))->execute(new ArrayInput, $output);

        return $output;
    }
}
