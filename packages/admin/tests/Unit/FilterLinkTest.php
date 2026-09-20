<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Criteria;
use Hydra\Admin\Link;
use Hydra\Admin\Page;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\ArrayWritableSource;
use Hydra\Admin\Tests\Support\DescribedSource;
use Hydra\Admin\Tests\Support\DescribedUsersModule;
use Hydra\Admin\Tests\Support\LinkedWritableModule;
use Hydra\Admin\Tests\Support\TicketSource;
use Hydra\Admin\Tests\Support\TicketsModule;
use Hydra\Admin\ViewModels\ListViewModel;
use Hydra\Http\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Filter links end to end: the bar renders with the table and is clickable at
 * once, the tallies arrive afterwards on a request of their own, and what a
 * link counts is what clicking it shows.
 */
#[CoversClass(Link::class)]
#[CoversClass(Criteria::class)]
#[CoversClass(ListViewModel::class)]
final class FilterLinkTest extends TestCase
{
    private AdminHarness $admin;

    protected function setUp(): void
    {
        $this->admin = new AdminHarness(
            [TicketsModule::class => new TicketsModule, TicketSource::class => new TicketSource],
            [TicketsModule::class],
        );
    }

    public function test_the_bar_renders_with_the_table_and_asks_for_its_tallies_after(): void
    {
        $body = $this->list('/admin/tickets');

        $this->assertStringContainsString('id="admin-links"', $body);
        $this->assertStringContainsString('>Open<', $body);
        $this->assertStringContainsString('>Closed<', $body);
        // The table is already there; only the numbers are still coming.
        $this->assertStringContainsString('cannot sign in', $body);
        $this->assertStringContainsString('/admin/tickets/counts', $body);
    }

    public function test_a_link_filters_the_table(): void
    {
        $body = $this->list('/admin/tickets?view=open');

        $this->assertStringContainsString('cannot sign in', $body);
        $this->assertStringContainsString('invoice is wrong', $body);
        $this->assertStringNotContainsString('refund issued', $body);
        $this->assertStringNotContainsString('password reset', $body);
    }

    public function test_a_link_nothing_answers_to_leaves_the_list_alone(): void
    {
        $body = $this->list('/admin/tickets?view=nonsense');

        $this->assertStringContainsString('cannot sign in', $body);
        $this->assertStringContainsString('refund issued', $body);
    }

    public function test_a_toolbar_filter_applies_on_top_of_a_link(): void
    {
        // The link pins status, and the select overrides it for that column:
        // whichever the visitor touched last is the one showing.
        $body = $this->list('/admin/tickets?view=open&status=pending');

        $this->assertStringContainsString('password reset', $body);
        $this->assertStringNotContainsString('cannot sign in', $body);
    }

    public function test_the_toolbar_carries_the_link_it_is_filtering_inside(): void
    {
        // The toolbar renders outside the swappable body and holds no input for
        // the view, so the body has to offer one for it to include. Without it
        // a search would drop the visitor back to the unfiltered table.
        $body = $this->list('/admin/tickets?view=open');

        $this->assertStringContainsString('<input type="hidden" name="view" value="open">', $body);
    }

    public function test_the_tallies_come_back_as_the_bar_that_asked_for_them(): void
    {
        $body = $this->counts('/admin/tickets/counts');

        $this->assertMatchesRegularExpression('/Open\s*<span[^>]*>2</', $body);
        $this->assertMatchesRegularExpression('/Closed\s*<span[^>]*>2</', $body);
        // "All" pins nothing, so it counts the table.
        $this->assertMatchesRegularExpression('/All\s*<span[^>]*>5</', $body);
    }

    public function test_the_bar_that_comes_back_does_not_ask_again(): void
    {
        // Its own hx-get is what ends the exchange after one round trip; a copy
        // carrying one would fetch its tallies forever.
        $this->assertStringNotContainsString('hx-get="/admin/tickets/counts', $this->counts('/admin/tickets/counts'));
    }

    public function test_a_tally_counts_what_clicking_the_link_would_show(): void
    {
        // A search is the visitor's and narrows every tally; the column a link
        // pins is the link's, and the select asking for another value does not
        // get to make its count disagree with the table it leads to.
        $body = $this->counts('/admin/tickets/counts?status=pending');

        $this->assertMatchesRegularExpression('/Open\s*<span[^>]*>2</', $body);
        $this->assertMatchesRegularExpression('/All\s*<span[^>]*>1</', $body);
    }

    public function test_the_active_link_survives_the_tallies_landing_on_it(): void
    {
        // Asked for through the URL the bar actually generates, not one written
        // out here. Spelling it by hand is how this passed while the bar was
        // asking for something else, and a link that shows as current until its
        // tally lands and then stops is precisely what that looked like.
        $url = (string) $this->viewModel('/admin/tickets?view=open')->countsUrl();

        $this->assertStringContainsString('aria-current="page"', $this->counts($url));
    }

    public function test_a_link_clears_the_toolbar_filter_it_pins(): void
    {
        $vm = $this->viewModel('/admin/tickets?status=pending');

        $this->assertSame(
            ['sort' => 'id', 'dir' => 'asc', 'view' => 'open'],
            $this->query($vm->linkUrl($this->link('open'))),
        );
    }

    public function test_a_link_keeps_the_search_and_the_order_that_are_not_its_business(): void
    {
        $vm = $this->viewModel('/admin/tickets?q=refund&sort=subject&dir=desc&page=3');
        $params = $this->query($vm->linkUrl($this->link('closed')));

        $this->assertSame('refund', $params['q'] ?? null);
        $this->assertSame('subject', $params['sort'] ?? null);
        $this->assertSame('desc', $params['dir'] ?? null);
        // A new view starts at its own beginning, not at page 3 of the old one.
        $this->assertArrayNotHasKey('page', $params);
    }

    public function test_the_url_spells_the_link_once_and_not_its_filters_as_well(): void
    {
        $criteria = Criteria::fromQuery(Query::fromUrl('/admin/tickets?view=open'), $this->blueprint());

        $this->assertSame(['status' => 'open'], $criteria->filters);
        $this->assertSame(['sort' => 'id', 'dir' => 'asc', 'view' => 'open'], $criteria->toQuery());
    }

    public function test_sorting_and_paging_stay_inside_the_link(): void
    {
        $vm = $this->viewModel('/admin/tickets?view=open');

        $this->assertSame('open', $this->query($vm->sortLink($this->blueprint()->fields[1]))['view'] ?? null);
        $this->assertSame('open', $this->query($vm->pageLink(2))['view'] ?? null);
    }

    public function test_a_link_that_pins_nothing_is_current_on_arrival(): void
    {
        // The module's own URL already serves the list "All" links to, so the
        // bar arrives with a current link rather than with none until the
        // visitor clicks the one they are already looking at.
        $vm = $this->viewModel('/admin/tickets');

        $this->assertTrue($vm->isActiveLink($this->link('all')));
        $this->assertFalse($vm->isActiveLink($this->link('open')));
        $this->assertTrue($this->viewModel('/admin/tickets?view=open')->isActiveLink($this->link('open')));
    }

    public function test_asking_for_a_view_takes_the_standing_one_off(): void
    {
        $vm = $this->viewModel('/admin/tickets?view=open');

        $this->assertFalse($vm->isActiveLink($this->link('all')));
    }

    public function test_where_every_link_pins_something_none_is_current_on_arrival(): void
    {
        $admin = new AdminHarness(
            [DescribedUsersModule::class => new DescribedUsersModule, DescribedSource::class => new DescribedSource],
            [DescribedUsersModule::class],
        );

        $body = (string) $admin->controller->list($admin->request('GET', '/admin/users'))->getBody();

        $this->assertStringContainsString('admin-links', $body);
        $this->assertStringNotContainsString('aria-current="page"', $body);
    }

    public function test_a_pending_tally_is_not_drawn_with_a_bootstrap_placeholder(): void
    {
        // A placeholder fills itself with currentColor, and on the link just
        // clicked that is the strongest ink on the page: a solid dark block
        // where the number goes. The pill holds its own width empty instead.
        $body = $this->list('/admin/tickets');

        $this->assertStringContainsString('admin-link-count-pending', $body);
        $this->assertStringNotContainsString('placeholder-glow', $body);
    }

    public function test_a_pending_tally_is_already_the_size_the_number_will_be(): void
    {
        // A box with no content has no line box, so an empty pill collapses to
        // a sliver and the bar grows the moment the numbers land. The space is
        // what gives it a digit's height while it waits.
        $this->assertStringContainsString(
            'admin-link-count-pending" aria-hidden="true">&nbsp;</span>',
            $this->list('/admin/tickets'),
        );
    }

    public function test_the_counts_url_drops_the_order_a_count_cannot_depend_on(): void
    {
        // Sorting cannot change how many rows match, so carrying it only put
        // one answer behind several URLs. The view stays: no tally is counted
        // through it, but the bar that comes back is rendered from it.
        $vm = $this->viewModel('/admin/tickets?q=refund&sort=subject&dir=desc&page=3&view=open');

        $this->assertSame(['q' => 'refund', 'view' => 'open'], $this->query((string) $vm->countsUrl()));
    }

    public function test_the_counts_url_keeps_a_filter_asked_for_on_top_of_a_link(): void
    {
        // This one does move the numbers, so it is the difference between two
        // cached answers rather than noise on the end of one.
        $vm = $this->viewModel('/admin/tickets?status=pending&sort=subject');

        $this->assertSame(['status' => 'pending'], $this->query((string) $vm->countsUrl()));
    }

    public function test_the_tallies_may_be_answered_from_the_browsers_own_cache(): void
    {
        $response = $this->admin->controller->counts($this->admin->request('GET', '/admin/tickets/counts'));

        $this->assertSame('private, max-age=10', $response->getHeaderLine('Cache-Control'));
        // A tally is one account's view of the table, not the next visitor's.
        $this->assertSame('Cookie', $response->getHeaderLine('Vary'));
    }

    public function test_a_write_sends_the_bar_to_an_address_the_cache_has_not_seen(): void
    {
        // The browser is holding tallies from before the row moved, and this is
        // the one moment they must not be allowed to answer. A plain read is
        // happy to be answered from cache; a write is not.
        $admin = new AdminHarness(
            [
                LinkedWritableModule::class => new LinkedWritableModule,
                ArrayWritableSource::class => new ArrayWritableSource,
            ],
            [LinkedWritableModule::class],
        );

        $body = (string) $admin->controller->update(
            $admin->request('POST', '/admin/users/1/edit', $admin->body(), ['username' => 'hopper']),
        )->getBody();

        $this->assertStringContainsString('_fresh=', $body);
    }

    public function test_a_read_leaves_the_bar_at_the_address_the_cache_already_holds(): void
    {
        $this->assertStringNotContainsString('_fresh', $this->list('/admin/tickets'));
    }

    private function list(string $path): string
    {
        return (string) $this->admin->controller->list($this->admin->request('GET', $path))->getBody();
    }

    private function counts(string $path): string
    {
        return (string) $this->admin->controller->counts($this->admin->request('GET', $path))->getBody();
    }

    private function viewModel(string $path): ListViewModel
    {
        $blueprint = $this->blueprint();
        $criteria = Criteria::fromQuery(Query::fromUrl($path), $blueprint);

        return new ListViewModel($blueprint, new Page([], 0, $criteria), '/admin');
    }

    private function blueprint(): Blueprint
    {
        return (new TicketsModule)->define()->compile();
    }

    private function link(string $key): Link
    {
        $link = $this->blueprint()->link($key);

        $this->assertInstanceOf(Link::class, $link);

        return $link;
    }

    /** @return array<string, string> */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        /** @var array<string, string> $params */
        return $params;
    }
}
