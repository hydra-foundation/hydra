<?php

declare(strict_types=1);

namespace Hydra\Tests\Integration;

use Hydra\Http\Testing\Client;
use Hydra\Tests\Fixture\Entities\Role;
use Hydra\Tests\Fixture\Fixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A module over a real table, end to end: the routes it compiles to, the
 * ability it declares, the writes its source refuses, and the three depths htmx
 * can ask a screen to render at.
 */
#[CoversNothing]
final class AdminModuleFlowTest extends TestCase
{
    private const FRAME = 'div#admin-frame';

    private Fixture $app;

    private Client $http;

    protected function setUp(): void
    {
        $this->app = Fixture::boot();
        $this->http = $this->app->http();

        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk');

        // Enough extra rows to push the 15-per-page module onto a second page.
        foreach (range(1, 20) as $n) {
            $this->app->seed(sprintf('temp%02d', $n));
        }
    }

    public function test_the_module_compiles_to_a_route_that_anonymous_visitors_cannot_reach(): void
    {
        $this->http->get('/admin/users')->assertStatus(302)->assertRedirect('/login');
    }

    public function test_a_module_ability_keeps_signed_in_non_admins_out(): void
    {
        $this->login('clerk');

        $this->http->get('/admin/users')->assertStatus(403);
    }

    public function test_an_admin_sees_the_first_page_of_the_table(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users');

        $this->assertStringContainsString('<title>Users · Admin</title>', $body);
        $this->assertStringContainsString('id="admin-frame"', $body);
        $this->assertStringContainsString('id="admin-body"', $body);
        $this->assertSame(['Admin', 'Administration', 'Users'], $this->crumbs($body));
        $this->assertStringContainsString('Showing 1–15 of 22', $body);
        $this->assertStringContainsString('>temp20</td>', $body);
        // The column the source never selects cannot reach a screen.
        $this->assertStringNotContainsString('password_hash', $body);
    }

    public function test_a_create_screen_opens_a_blank_form(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users/new');

        $this->assertStringContainsString('<title>New user · Admin</title>', $body);
        $this->assertSame(['Admin', 'Administration', 'Users', 'New'], $this->crumbs($body));
        $this->assertMatchesRegularExpression('/breadcrumb-item active">\s*New\s*<\/li>/', $body);
        $this->assertStringContainsString('hx-post="/admin/users/new"', $body);
        $this->assertStringContainsString('value=""', $body);
        // Apply saves and stays; a row that does not exist yet has nowhere to stay.
        $this->assertStringNotContainsString('value="apply"', $body);
    }

    public function test_a_create_screen_writes_the_row_and_opens_it(): void
    {
        $this->login('boss');
        // 22 seeded rows, so the row just written is 23: the id create() returned.
        $this->http->post('/admin/users/new', [
            'username' => 'newcomer',
            'role' => 'user',
            'password' => 'correct-horse',
        ])->assertStatus(302)->assertRedirect('/admin/users/23');

        $this->assertStringContainsString('>newcomer</td>', $this->body('/admin/users?q=newcomer'));
    }

    public function test_an_htmx_create_hands_back_the_row_it_wrote(): void
    {
        $this->login('boss');
        $response = $this->http->htmx(self::FRAME)
            ->post('/admin/users/new', ['username' => 'newcomer', 'role' => 'user', 'password' => 'correct-horse'])
            ->assertOk()
            ->assertSee('Created')
            // The show screen for that row, not the table it is one line of.
            ->assertSee('>newcomer</dd>')
            ->assertSee('hx-get="/admin/users/23/edit"');

        $this->assertSame('/admin/users/23', $response->directive('push-url'));
    }

    public function test_a_create_screen_can_require_what_the_edit_screen_leaves_optional(): void
    {
        $this->login('boss');
        $this->http->post('/admin/users/new', [
            'username' => 'newcomer',
            'role' => 'user',
            'password' => '',
        ])->assertStatus(422)->assertSee('Set a password.');

        $this->assertStringContainsString('Nothing to show.', $this->body('/admin/users?q=newcomer'));
    }

    public function test_the_source_rejects_a_name_another_row_already_holds(): void
    {
        $this->login('boss');
        $this->http->post('/admin/users/new', [
            'username' => 'clerk',
            'role' => 'user',
            'password' => 'correct-horse',
        ])->assertStatus(422)->assertSee('already taken');
    }

    public function test_the_list_offers_the_way_to_a_new_row(): void
    {
        $this->login('boss');

        $this->assertStringContainsString('hx-get="/admin/users/new"', $this->body('/admin/users'));
    }

    public function test_a_show_screen_reads_one_row(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users/1');

        $this->assertStringContainsString('<title>User · Admin</title>', $body);
        $this->assertSame(['Admin', 'Administration', 'Users', '1'], $this->crumbs($body));
        $this->assertStringContainsString('>boss</dd>', $body);
        $this->assertStringContainsString('>Admin</dd>', $body);
        $this->assertStringNotContainsString('password_hash', $body);
        $this->assertStringContainsString('hx-get="/admin/users/1/edit"', $body);
    }

    public function test_a_show_screen_is_a_404_when_nothing_has_that_id(): void
    {
        $this->login('boss');

        $this->http->get('/admin/users/999')->assertStatus(404);
    }

    public function test_the_table_offers_a_way_into_each_row(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users');

        // One of each per row, for the 15 rows this page holds.
        $this->assertSame(15, substr_count($body, '>View</a>'));
        $this->assertSame(15, substr_count($body, '>Edit</a>'));
        $this->assertStringContainsString('hx-get="/admin/users/22"', $body);
    }

    public function test_a_delete_removes_the_row_and_returns_to_the_list(): void
    {
        $this->login('boss');
        $this->http->post('/admin/users/2/delete')->assertStatus(302)->assertRedirect('/admin/users');

        $this->assertStringContainsString('Nothing to show.', $this->body('/admin/users?q=clerk'));
    }

    public function test_an_htmx_delete_hands_back_the_list_it_would_have_fetched(): void
    {
        $this->login('boss');
        $response = $this->http->htmx(self::FRAME)
            ->post('/admin/users/2/delete')
            ->assertOk()
            ->assertSee('Deleted')
            ->assertDontSee('>clerk</td>');

        $this->assertSame('/admin/users', $response->directive('push-url'));
    }

    public function test_a_delete_comes_back_to_the_view_of_the_list_it_was_made_from(): void
    {
        $this->login('boss');
        $response = $this->http->htmx(self::FRAME)->post('/admin/users/2/delete', [], [
            'HX-Current-URL' => 'http://localhost/admin/users?q=temp&sort=username&dir=asc&page=2',
        ]);

        $this->assertSame(
            '/admin/users?q=temp&sort=username&dir=asc&page=2',
            urldecode((string) $response->directive('push-url')),
        );
        // The search it came back to is the search it was sent from.
        $response->assertSee('value="temp"')->assertSee('>temp16</td>')->assertDontSee('>temp01</td>');
    }

    public function test_a_delete_that_empties_the_last_page_falls_back_to_the_new_end(): void
    {
        $this->login('boss');
        // 22 rows, 15 to a page: page 2 holds seven, and one search holds one.
        $response = $this->http->htmx(self::FRAME)->post('/admin/users/22/delete', [], [
            'HX-Current-URL' => 'http://localhost/admin/users?q=temp20&page=2',
        ]);

        // The page it was on is gone; the rest of the view it was asked for is not.
        $this->assertSame(
            '/admin/users?q=temp20&sort=id&dir=desc',
            urldecode((string) $response->directive('push-url')),
        );
        $response->assertSee('Nothing to show.');
    }

    public function test_a_write_sent_without_htmx_lands_on_the_modules_own_view(): void
    {
        $this->login('boss');

        $this->assertSame('/admin/users', $this->http->post('/admin/users/2/delete')->header('Location'));
    }

    public function test_the_source_refuses_to_delete_the_account_doing_the_deleting(): void
    {
        $this->login('boss');
        $this->http->post('/admin/users/1/delete')
            ->assertStatus(422)
            ->assertSee('alert-danger')
            ->assertSee('You cannot delete the account you are signed in as.');
        // A refusal is not a redirect: the row it refused to remove is still there.
        $this->assertStringContainsString('>boss</td>', $this->body('/admin/users?q=boss'));
    }

    public function test_a_delete_screen_is_a_post_or_it_is_nothing(): void
    {
        $this->login('boss');

        // The path is real, the method is not: anything that crawls links gets 405.
        $this->http->get('/admin/users/2/delete')->assertStatus(405);
    }

    public function test_both_the_table_and_the_show_screen_offer_a_way_to_remove_a_row(): void
    {
        $this->login('boss');

        $list = $this->body('/admin/users');
        $this->assertSame(15, substr_count($list, '>Delete</button>'));
        $this->assertStringContainsString('hx-post="/admin/users/22/delete"', $list);
        $this->assertStringContainsString('hx-confirm="Delete this user? This cannot be undone."', $list);

        $show = $this->body('/admin/users/2');
        $this->assertStringContainsString('hx-post="/admin/users/2/delete"', $show);
    }

    public function test_an_edit_screen_hangs_below_its_module(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users/1/edit');

        $this->assertStringContainsString('<title>Edit user · Admin</title>', $body);
        $this->assertSame(['Admin', 'Administration', 'Users', 'Edit 1'], $this->crumbs($body));
        $this->assertStringContainsString('>Users</a>', $body);
        $this->assertStringContainsString('Edit 1', $body);
    }

    public function test_a_breadcrumb_link_swaps_the_frame_rather_than_reloading(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users/1/edit');

        $this->assertStringContainsString('hx-get="/admin/users"', $body);
        $this->assertStringContainsString('hx-get="/admin/dashboard"', $body);
        $this->assertStringNotContainsString('hx-get="/admin"', $body);
    }

    public function test_the_admin_root_redirects_to_the_landing_module(): void
    {
        $this->login('boss');
        $this->http->get('/admin')->assertStatus(302)->assertRedirect('/admin/dashboard');
    }

    public function test_a_page_module_needs_no_source_and_is_open_to_any_signed_in_user(): void
    {
        $this->login('clerk');
        $body = $this->body('/admin/dashboard');

        $this->assertStringContainsString('Dashboard', $body);
        $this->assertStringContainsString('Newest accounts', $body);
        $this->assertStringContainsString('Signed in as <strong>clerk</strong>', $body);
        $this->assertSame(['Admin', 'Overview', 'Dashboard'], $this->crumbs($body));
    }

    public function test_the_presenter_supplies_the_page_its_numbers(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/dashboard');

        // 22 seeded accounts, one of them an admin. One tile per role, labelled
        // from the enum, so the plain-user tile carries the other 21.
        $this->assertMatchesRegularExpression('/Users<\/div>\s*<div class="stat-value">22</', $body);
        $this->assertMatchesRegularExpression('/Admin<\/div>\s*<div class="stat-value">1</', $body);
        $this->assertMatchesRegularExpression('/User<\/div>\s*<div class="stat-value">21</', $body);
    }

    public function test_the_sidebar_shows_only_the_modules_the_visitor_may_reach(): void
    {
        $this->login('clerk');
        $body = $this->body('/admin/dashboard');

        $this->assertStringContainsString('/admin/dashboard', $body);
        $this->assertStringNotContainsString('/admin/users', $body);
    }

    public function test_a_path_below_a_module_with_no_screen_there_is_not_a_route(): void
    {
        $this->login('boss');

        $this->http->get('/admin/dashboard/trends')->assertStatus(404);
    }

    public function test_an_unknown_slug_under_the_prefix_is_not_a_route(): void
    {
        $this->login('boss');

        $this->http->get('/admin/invoices')->assertStatus(404);
    }

    public function test_search_filter_and_sort_travel_in_the_query_string(): void
    {
        $this->login('boss');

        $searched = $this->body('/admin/users?q=clerk');
        $this->assertStringContainsString('Showing 1–1 of 1', $searched);
        $this->assertStringContainsString('clerk', $searched);

        $filtered = $this->body('/admin/users?role=admin');
        $this->assertStringContainsString('Showing 1–1 of 1', $filtered);

        $sorted = $this->body('/admin/users?sort=username&dir=asc');
        $this->assertLessThan(strpos($sorted, '>clerk<'), strpos($sorted, '>boss<'));
    }

    /**
     * Criteria::searchPattern() escapes the term's own wildcards so a search
     * cannot ask for a full scan, and the LIKE has to name the escape character
     * for that to mean anything: SQLite assumes none, so without the ESCAPE
     * clause the backslash is matched literally and an underscore, ordinary in
     * a username, finds nothing.
     */
    public function test_a_search_term_containing_a_wildcard_matches_it_literally(): void
    {
        $this->login('boss');

        $this->http->post('/admin/users/new', [
            'username' => 'ada_lovelace',
            'role' => 'user',
            'password' => 'correct-horse',
        ])->assertRedirect();

        $found = $this->body('/admin/users?q=ada_lovelace');
        $this->assertStringContainsString('>ada_lovelace</td>', $found);
        $this->assertStringContainsString('Showing 1–1 of 1', $found);

        // The other half of the same guard: a bare wildcard is a search for the
        // character, not a request for every row in the table.
        $this->assertStringContainsString('Nothing to show.', $this->body('/admin/users?q=%25'));
    }

    public function test_an_undeclared_sort_column_is_ignored(): void
    {
        $this->login('boss');

        $this->http->get('/admin/users?sort=password_hash')->assertStatus(200);
    }

    public function test_the_second_page_is_its_own_url(): void
    {
        $this->login('boss');

        $this->assertStringContainsString('Showing 16–22 of 22', $this->body('/admin/users?page=2'));
    }

    public function test_htmx_swaps_only_the_body_when_the_table_is_targeted(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users?page=2', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-body']);

        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('admin-sidebar', $body);
        $this->assertStringNotContainsString('breadcrumb', $body);
        $this->assertStringContainsString('Showing 16–22 of 22', $body);
    }

    public function test_htmx_swaps_the_frame_when_the_sidebar_navigates(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-frame']);

        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('admin-sidebar', $body);
        $this->assertStringContainsString('breadcrumb', $body);
        $this->assertStringContainsString('id="admin-body"', $body);
    }

    public function test_a_frame_swap_carries_the_title_and_an_out_of_band_sidebar(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-frame']);

        // htmx reads the title out of a head block and applies it to the tab.
        $this->assertStringContainsString('<head><title>Users · Admin</title></head>', $body);

        // The sidebar lives outside the swapped frame, so the active item rides
        // along out of band.
        $this->assertStringContainsString('id="admin-nav" hx-swap-oob="true"', $body);
        $this->assertMatchesRegularExpression('/nav-link active"\s+href="\/admin\/users"/', $body);
        $this->assertMatchesRegularExpression('/nav-link"\s+href="\/admin\/dashboard"/', $body);
    }

    public function test_a_body_swap_leaves_the_sidebar_alone(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users?page=2', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-body']);

        // The export link rides out of band on purpose: it lives in the toolbar
        // outside the body, and its href carries the criteria. Nothing else may.
        $this->assertStringNotContainsString('id="admin-nav" hx-swap-oob', $body);
        $this->assertSame(1, substr_count($body, 'hx-swap-oob'));
        $this->assertStringContainsString('id="admin-export" hx-swap-oob', $body);
        $this->assertStringNotContainsString('<head>', $body);
    }

    public function test_an_htmx_request_with_no_known_target_still_renders_the_whole_page(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/users', ['HX-Request' => 'true', 'HX-Target' => 'div#somewhere-else']);

        $this->assertStringContainsString('<html', $body);
        $this->assertStringContainsString('admin-sidebar', $body);
    }

    public function test_a_body_swap_carries_the_sort_state_the_toolbar_reaches_for(): void
    {
        $this->login('boss');

        $full = $this->body('/admin/users');
        $this->assertStringContainsString('hx-include="#admin-sort-state"', $full);
        $this->assertSame(1, substr_count($full, 'name="sort"'));

        $sorted = $this->body('/admin/users?sort=username&dir=asc', [
            'HX-Request' => 'true',
            'HX-Target' => 'div#admin-body',
        ]);
        $this->assertStringContainsString('id="admin-sort-state"', $sorted);
        $this->assertStringContainsString('name="sort" value="username"', $sorted);
        $this->assertStringContainsString('name="dir" value="asc"', $sorted);
    }

    /**
     * The trail as a visitor reads it, link or not: a group contributes a crumb
     * with no page behind it.
     *
     * @return list<string>
     */
    private function crumbs(string $body): array
    {
        preg_match_all('~<li class="breadcrumb-item[^"]*">(.*?)</li>~s', $body, $matches);

        return array_map(
            static fn (string $crumb): string => trim(strip_tags($crumb)),
            $matches[1],
        );
    }

    /** @param array<string, string> $headers */
    private function body(string $path, array $headers = []): string
    {
        return $this->http->get($path, $headers)->assertOk()->body();
    }

    private function login(string $username): void
    {
        $this->app->login($username)->assertRedirect('/admin');
    }
}
