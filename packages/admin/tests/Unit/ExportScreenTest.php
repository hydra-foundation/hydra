<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use DateTimeImmutable;
use Hydra\Admin\AdminController;
use Hydra\Admin\Extractor;
use Hydra\Admin\Screens\ExportScreen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The declaration behind a download: where it answers, what the saved file is
 * called, and how much of a table one request may walk out with.
 */
#[CoversClass(ExportScreen::class)]
final class ExportScreenTest extends TestCase
{
    public function test_it_answers_a_get_under_the_module(): void
    {
        $screen = ExportScreen::make();

        $this->assertSame('export', $screen->name());
        $this->assertSame('GET', $screen->method());
        $this->assertSame('export', $screen->path());
        $this->assertSame([AdminController::class, 'export'], $screen->handler());
        $this->assertNull($screen->ability());
    }

    /**
     * An export hands a whole table to whoever can open the screen, which is
     * not obviously the same people who may read a page of it.
     */
    public function test_it_can_be_gated_apart_from_the_module(): void
    {
        $this->assertSame('ExportUsers', ExportScreen::make()->requires('ExportUsers')->ability());
    }

    public function test_without_a_date_the_file_is_dated_today(): void
    {
        // Either side of midnight, so the test does not fail at the stroke of it.
        $before = date('Y-m-d');
        $name = ExportScreen::make()->filename('users');
        $after = date('Y-m-d');

        $this->assertContains($name, ["users-{$before}.csv", "users-{$after}.csv"]);
    }

    public function test_the_file_is_named_after_the_module_and_dated(): void
    {
        $this->assertSame('users-2026-03-04.csv', ExportScreen::make()->filename('users', $this->on()));
    }

    public function test_a_module_may_name_the_file_itself(): void
    {
        $this->assertSame('people-2026-03-04.csv', ExportScreen::make()->named('people')->filename('users', $this->on()));
    }

    /**
     * The name reaches a header and then somebody's disk, so what a module
     * wrote is reduced to characters both carry everywhere.
     */
    public function test_a_name_is_reduced_to_something_a_filesystem_will_take(): void
    {
        $screen = ExportScreen::make()->named('../../etc/passwd');

        $this->assertSame('etc-passwd-2026-03-04.csv', $screen->filename('users', $this->on()));
        $this->assertSame('export-2026-03-04.csv', ExportScreen::make()->named('///')->filename('users', $this->on()));
    }

    public function test_the_row_limit_defaults_to_the_extractor_cap_and_can_be_moved(): void
    {
        $this->assertSame(Extractor::MAX_ROWS, ExportScreen::make()->rowLimit());
        $this->assertSame(10, ExportScreen::make()->limit(10)->rowLimit());
        $this->assertSame(1_000_000, ExportScreen::make()->limit(1_000_000)->rowLimit());
        $this->assertSame(1, ExportScreen::make()->limit(0)->rowLimit());
    }

    public function test_the_button_says_what_the_module_says_it_says(): void
    {
        $this->assertSame('Export CSV', ExportScreen::make()->label());
        $this->assertSame('Download', ExportScreen::make()->labelled('Download')->label());
    }

    public function test_every_builder_leaves_the_original_alone(): void
    {
        $screen = ExportScreen::make();
        $screen->requires('X')->named('other')->limit(1)->labelled('Other');

        $this->assertNull($screen->ability());
        $this->assertSame('Export CSV', $screen->label());
        $this->assertSame(Extractor::MAX_ROWS, $screen->rowLimit());
        $this->assertSame('users-2026-03-04.csv', $screen->filename('users', $this->on()));
    }

    private function on(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-03-04 10:00:00');
    }
}
