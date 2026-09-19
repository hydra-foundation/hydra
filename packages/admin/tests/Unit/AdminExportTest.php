<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Csv;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CappedUsersModule;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\Tests\Support\ExportableUsersModule;
use Hydra\Authorization\Exceptions\AuthorizationException;
use Hydra\Http\Exceptions\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A module's list, downloaded: the request the button sends, the file that
 * comes back, and the two things that separate an export from the table it was
 * taken from — it is not bounded by the page, and it is not narrowed to the
 * columns the page had room for.
 */
#[CoversClass(AdminController::class)]
final class AdminExportTest extends TestCase
{
    private CrudUserSource $source;
    private AdminHarness $admin;

    protected function setUp(): void
    {
        $this->source = new CrudUserSource;
        $this->admin = new AdminHarness(
            [ExportableUsersModule::class => new ExportableUsersModule, CrudUserSource::class => $this->source],
            [ExportableUsersModule::class],
        );
    }

    public function test_the_response_is_a_file_the_browser_saves(): void
    {
        $response = $this->export('/admin/users/export');
        $disposition = $response->getHeaderLine('Content-Disposition');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString('people-2026-01-01.csv', $disposition);
    }

    public function test_the_file_is_dated_by_the_clock(): void
    {
        $this->admin->clock->set('2031-07-09 23:59:59');

        $this->assertStringContainsString(
            'people-2031-07-09.csv',
            $this->export('/admin/users/export')->getHeaderLine('Content-Disposition'),
        );
    }

    /**
     * The module pages two at a time. An export bounded by that would hand over
     * the two rows the visitor could already see.
     */
    public function test_it_carries_every_row_and_not_the_page_the_list_shows(): void
    {
        $csv = $this->body('/admin/users/export');

        foreach (['ada', 'grace', 'alan', 'edsger', 'barbara'] as $username) {
            $this->assertStringContainsString('"' . $username . '"', $csv);
        }
    }

    /**
     * "note" is hidden on the list because the table has no room for it. A
     * spreadsheet has room for it, which is half of what an export is for.
     */
    public function test_it_writes_the_columns_the_table_had_no_room_for(): void
    {
        $csv = $this->body('/admin/users/export');

        $this->assertStringContainsString('"Id","Username","Note"', $csv);
        $this->assertStringContainsString('"about ada"', $csv);
    }

    public function test_it_downloads_the_view_the_visitor_filtered(): void
    {
        $csv = $this->body('/admin/users/export?q=ada');

        $this->assertStringContainsString('"ada"', $csv);
        $this->assertStringNotContainsString('"grace"', $csv);
    }

    public function test_it_orders_the_file_the_way_the_list_was_ordered(): void
    {
        $csv = $this->body('/admin/users/export?sort=username&dir=asc');
        $names = [];

        foreach (explode(Csv::EOL, trim($csv)) as $line) {
            $names[] = explode(',', $line)[1];
        }

        $this->assertSame(['"Username"', '"ada"', '"alan"', '"barbara"', '"edsger"', '"grace"'], $names);
    }

    public function test_it_downloads_the_view_the_visitor_narrowed_with_a_filter(): void
    {
        $csv = $this->body('/admin/users/export?role=admin');

        $this->assertStringContainsString('"ada"', $csv);
        $this->assertStringContainsString('"grace"', $csv);
        $this->assertStringNotContainsString('"alan"', $csv);
        $this->assertStringNotContainsString('"barbara"', $csv);
    }

    public function test_a_filter_the_module_never_offered_narrows_nothing(): void
    {
        // Criteria whitelists against the blueprint, so a hand-typed key cannot
        // reach the source and quietly empty somebody's download.
        $this->assertSame(
            $this->body('/admin/users/export'),
            $this->body('/admin/users/export?note=about+ada'),
        );
    }

    public function test_a_module_may_cap_what_one_download_walks_out_with(): void
    {
        $admin = new AdminHarness(
            [CappedUsersModule::class => new CappedUsersModule, CrudUserSource::class => $this->source],
            [CappedUsersModule::class],
        );
        $csv = (string) $admin->controller->export($admin->request('GET', '/admin/users/export'))->getBody();

        // The heading and the two rows the cap allows, and nothing after them.
        $this->assertSame(3, substr_count($csv, Csv::EOL));
    }

    public function test_the_list_offers_the_download_with_the_view_in_the_link(): void
    {
        $body = (string) $this->admin->controller->list($this->admin->request('GET', '/admin/users?q=ada'))->getBody();

        $this->assertStringContainsString('Download CSV', $body);
        $this->assertStringContainsString('/admin/users/export?q=ada', $body);
    }

    /**
     * The toolbar renders outside the swappable body so the search box keeps
     * focus, which means it does not re-render when a filter swaps the table.
     * Without an out-of-band copy the export button keeps the href it was built
     * with on the last full page load, and the visitor downloads the unfiltered
     * list from a screen showing a filtered one.
     */
    public function test_filtering_the_table_updates_the_download_link_with_it(): void
    {
        $swap = (string) $this->admin->controller->list(
            $this->admin->request('GET', '/admin/users?role=admin&q=ada', $this->admin->body()),
        )->getBody();

        $this->assertStringContainsString('<div id="admin-export" hx-swap-oob="true">', $swap);
        $this->assertStringContainsString('/admin/users/export?q=ada', $swap);
        $this->assertStringContainsString('role=admin', $swap);
    }

    /**
     * The out-of-band copy is sent at the body depth and nowhere else: two
     * elements sharing the id would make the swap target ambiguous.
     */
    public function test_the_download_link_is_declared_once_at_every_other_depth(): void
    {
        foreach ([[], $this->admin->frame()] as $headers) {
            $body = (string) $this->admin->controller->list(
                $this->admin->request('GET', '/admin/users', $headers),
            )->getBody();

            // Bare id, no hx-swap-oob on it: the toolbar rendered it inline.
            // (The frame carries an out-of-band sidebar of its own, which is why
            // this asks about the export element rather than about the response.)
            $this->assertSame(1, substr_count($body, 'id="admin-export"'));
            $this->assertStringContainsString('<div id="admin-export">', $body);
        }
    }

    public function test_a_module_without_an_export_screen_has_nothing_to_download(): void
    {
        $admin = new AdminHarness(
            [CrudUsersModule::class => new CrudUsersModule, CrudUserSource::class => $this->source],
            [CrudUsersModule::class],
        );

        $this->expectException(NotFoundException::class);

        $admin->controller->export($admin->request('GET', '/admin/users'));
    }

    public function test_an_export_the_visitor_may_not_reach_is_refused_before_it_reads(): void
    {
        $admin = new AdminHarness(
            [ExportableUsersModule::class => new ExportableUsersModule, CrudUserSource::class => $this->source],
            [ExportableUsersModule::class],
            allowed: false,
        );

        $this->expectException(AuthorizationException::class);

        $admin->controller->export($admin->request('GET', '/admin/users/export'));
    }

    private function body(string $path): string
    {
        return (string) $this->export($path)->getBody();
    }

    private function export(string $path): ResponseInterface
    {
        return $this->admin->controller->export($this->admin->request('GET', $path));
    }
}
