<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Console\AdminCheckCommand;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Tests\Support\ArrayContainer;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\DescribedSource;
use Hydra\Admin\Tests\Support\DescribedUsersModule;
use Hydra\Admin\Tests\Support\LandingModule;
use Hydra\Admin\Tests\Support\UsersModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The one pairing nothing else in the toolchain can see: a module's field names
 * against the column list of the source it reads. Every case here is a bug that
 * ships silently — the screen renders, the request succeeds, and the answer is
 * wrong — so the command failing loudly is the whole behaviour under test.
 */
#[CoversClass(AdminCheckCommand::class)]
final class AdminCheckCommandTest extends TestCase
{
    public function test_a_module_naming_only_columns_its_source_offers_passes(): void
    {
        $tester = $this->tester(new DescribedSource);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('users', $tester->getDisplay());
        $this->assertStringContainsString('ok', $tester->getDisplay());
    }

    public function test_a_field_over_a_column_the_source_does_not_read_is_reported(): void
    {
        $tester = $this->tester(new DescribedSource(columns: ['id', 'username', 'role']));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('note', $tester->getDisplay());
        $this->assertStringContainsString('is not a column the source reads', $tester->getDisplay());
    }

    public function test_a_column_the_source_does_not_read_is_reported_once_and_not_four_times(): void
    {
        // Sorting, searching and filtering by a column the source never selects
        // are all consequences of the same mistake, and saying so four times
        // buries the line that names it.
        $tester = $this->tester(new DescribedSource(columns: ['id', 'role', 'note']));

        $this->assertSame(1, substr_count($tester->getDisplay(), 'username'));
    }

    public function test_a_sortable_field_the_source_will_not_order_by_is_reported(): void
    {
        $tester = $this->tester(new DescribedSource(sortable: ['id']));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('is sortable, but the source does not sort by it', $tester->getDisplay());
    }

    public function test_a_searchable_field_the_source_never_looks_in_is_reported(): void
    {
        $tester = $this->tester(new DescribedSource(searchable: []));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('is searchable, but the source does not search it', $tester->getDisplay());
    }

    public function test_a_filterable_field_the_source_never_narrows_on_is_reported(): void
    {
        // The reason the command exists: the filter renders, submits, narrows
        // nothing, and the screen answers with the whole table.
        $tester = $this->tester(new DescribedSource(filterable: []));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('is filterable, but the source does not filter by it', $tester->getDisplay());
    }

    public function test_a_default_sort_the_source_will_not_honour_is_reported(): void
    {
        // Wrong on the very first request, before a visitor has touched a header.
        $tester = $this->tester(new DescribedSource(sortable: ['username'], defaultSort: 'username'));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('defaultSort is "id"', $tester->getDisplay());
        $this->assertStringContainsString('every list starts on "username" instead', $tester->getDisplay());
    }

    public function test_a_source_offering_more_than_the_module_names_is_not_a_problem(): void
    {
        // How one source stays shared between two modules showing different
        // halves of a table.
        $tester = $this->tester(new DescribedSource(
            columns: ['id', 'username', 'role', 'note', 'email'],
            sortable: ['id', 'username', 'email'],
            searchable: ['username', 'email'],
            filterable: ['role', 'email'],
        ));

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function test_a_source_that_cannot_describe_itself_is_unchecked_rather_than_passing(): void
    {
        // A check that quietly finds nothing is the same as no check at all, so
        // the one thing it must not do is print a tick.
        $registry = new ModuleRegistry(
            new ArrayContainer([UsersModule::class => new UsersModule, ArraySource::class => new ArraySource]),
            [UsersModule::class],
        );

        $tester = new CommandTester(new AdminCheckCommand($registry));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('not describable', $tester->getDisplay());
        $this->assertStringContainsString('DescribesColumnsInterface', $tester->getDisplay());
    }

    public function test_a_module_with_no_source_has_nothing_to_check(): void
    {
        $registry = new ModuleRegistry(
            new ArrayContainer([LandingModule::class => new LandingModule]),
            [LandingModule::class],
        );

        $tester = new CommandTester(new AdminCheckCommand($registry));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('home', $tester->getDisplay());
    }

    private function tester(DescribedSource $source): CommandTester
    {
        $registry = new ModuleRegistry(
            new ArrayContainer([
                DescribedUsersModule::class => new DescribedUsersModule,
                DescribedSource::class => $source,
            ]),
            [DescribedUsersModule::class],
        );

        $tester = new CommandTester(new AdminCheckCommand($registry));
        $tester->execute([]);

        return $tester;
    }
}
