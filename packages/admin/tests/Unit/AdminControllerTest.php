<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CreateOnlyUsersModule;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Authorization\Exceptions\AuthorizationException;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\HtmxResponse;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;

/**
 * The controller behind every generated route, exercised through the package's
 * own templates: what a request produces is asserted as rendered HTML and as
 * what the source is left holding.
 */
final class AdminControllerTest extends TestCase
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

    public function test_a_list_renders_the_page_the_query_string_asks_for(): void
    {
        $body = $this->render('list', 'GET', '/admin/users?page=2');

        // perPage is 2 and the module sorts by id ascending: page 2 is the third
        // and fourth rows, and neither neighbour may leak into it.
        $this->assertStringContainsString('alan', $body);
        $this->assertStringContainsString('edsger', $body);
        $this->assertStringNotContainsString('grace', $body);
        $this->assertStringNotContainsString('barbara', $body);
    }

    public function test_a_search_narrows_the_list_it_renders(): void
    {
        $body = $this->render('list', 'GET', '/admin/users?q=ada');

        $this->assertStringContainsString('ada', $body);
        $this->assertStringNotContainsString('grace', $body);
    }

    public function test_a_row_screen_renders_the_fields_the_table_had_no_room_for(): void
    {
        $body = $this->render('show', 'GET', '/admin/users/2');

        $this->assertStringContainsString('grace', $body);
        $this->assertStringContainsString('about grace', $body);
    }

    public function test_a_row_the_source_does_not_have_is_not_found(): void
    {
        $this->expectException(NotFoundException::class);

        $this->render('show', 'GET', '/admin/users/999');
    }

    public function test_a_create_writes_the_row_and_opens_it(): void
    {
        $response = $this->handle('store', 'POST', '/admin/users/new', [], ['username' => 'linus']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/users/6', $response->getHeaderLine('Location'));
        $this->assertSame('linus', $this->source->find('6')['username'] ?? null);
    }

    public function test_an_htmx_create_hands_back_the_row_it_wrote(): void
    {
        // A redirect would be turned into a full page load, so the row itself
        // comes back and the URL is pushed after it — in the body, which is the
        // only thing an htmx 4 client reads.
        $response = $this->handle('store', 'POST', '/admin/users/new', $this->admin->frame(), ['username' => 'linus']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/admin/users/6', HtmxResponse::directive($response, 'push-url'));
        $this->assertStringContainsString('linus', (string) $response->getBody());
    }

    public function test_a_create_with_nowhere_to_land_comes_back_to_the_list(): void
    {
        // The id is real and the row was written; the module simply declares no
        // screen for one row, so the list is what can still be shown.
        $admin = new AdminHarness(
            [CreateOnlyUsersModule::class => new CreateOnlyUsersModule, CrudUserSource::class => $this->source],
            [CreateOnlyUsersModule::class],
        );

        $response = $admin->controller->store(
            $admin->request('POST', '/admin/users/new', [], ['username' => 'linus']),
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/users', $response->getHeaderLine('Location'));
        $this->assertSame('linus', $this->source->find('6')['username'] ?? null);
    }

    public function test_a_submission_the_rules_reject_comes_back_as_the_form(): void
    {
        $response = $this->handle('store', 'POST', '/admin/users/new', [], ['username' => '']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Enter a username.', (string) $response->getBody());
        $this->assertSame(5, count($this->source->rows));
    }

    public function test_a_submission_the_source_refuses_comes_back_as_the_form(): void
    {
        // Nothing was wrong with the submission itself: only the source could
        // know, and what it says has to reach the input it is about.
        $response = $this->handle('store', 'POST', '/admin/users/new', [], ['username' => 'taken']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('That username is already taken.', (string) $response->getBody());
        $this->assertSame(5, count($this->source->rows));
    }

    public function test_an_edit_writes_the_row_and_returns_to_the_list(): void
    {
        $response = $this->handle('update', 'POST', '/admin/users/2/edit', [], ['username' => 'grace h']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/users', $response->getHeaderLine('Location'));
        $this->assertSame('grace h', $this->source->find('2')['username'] ?? null);
    }

    public function test_a_write_returns_to_the_view_of_the_list_it_was_made_from(): void
    {
        $response = $this->handle(
            'update',
            'POST',
            '/admin/users/2/edit',
            [...$this->admin->frame(), 'HX-Current-URL' => 'https://admin.test/admin/users?q=grace&page=1'],
            ['username' => 'grace h'],
        );

        // Spelled out the way the table's own sort and page links spell it.
        $this->assertSame('/admin/users?q=grace&sort=id&dir=asc', HtmxResponse::directive($response, 'push-url'));
        $this->assertStringContainsString('grace h', (string) $response->getBody());
    }

    public function test_a_delete_removes_the_row_and_says_so(): void
    {
        $response = $this->handle('destroy', 'POST', '/admin/users/2/delete', $this->admin->frame());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->source->find('2'));
        $this->assertStringContainsString('Deleted', (string) $response->getBody());
    }

    public function test_a_delete_the_source_refuses_says_why_and_leaves_the_row(): void
    {
        // Rendered rather than redirected: a redirect would throw away the only
        // account of why the row is still there.
        $response = $this->handle('destroy', 'POST', '/admin/users/1/delete', $this->admin->frame());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull($this->source->find('1'));
        $this->assertStringContainsString('The first user cannot be deleted.', (string) $response->getBody());
    }

    public function test_a_delete_that_empties_the_last_page_falls_back_to_the_new_end(): void
    {
        // Five rows, two per page: page 3 holds one row, and deleting it leaves
        // a page the visitor never asked to be shown.
        $response = $this->handle(
            'destroy',
            'POST',
            '/admin/users/5/delete',
            [...$this->admin->frame(), 'HX-Current-URL' => 'https://admin.test/admin/users?page=3'],
        );

        $this->assertSame('/admin/users?sort=id&dir=asc&page=2', HtmxResponse::directive($response, 'push-url'));
        $this->assertStringContainsString('alan', (string) $response->getBody());
    }

    public function test_a_screen_the_visitor_may_not_reach_is_refused_before_it_renders(): void
    {
        $admin = new AdminHarness(
            [CrudUsersModule::class => new CrudUsersModule, CrudUserSource::class => $this->source],
            [CrudUsersModule::class],
            allowed: false,
        );

        $this->expectException(AuthorizationException::class);

        $admin->controller->list($admin->request('GET', '/admin/users'));
    }

    public function test_a_path_that_belongs_to_no_module_is_not_found(): void
    {
        $this->expectException(NotFoundException::class);

        $this->render('list', 'GET', '/admin/nothing');
    }

    /** @param array<string, string> $headers */
    private function render(string $action, string $method, string $path, array $headers = []): string
    {
        return (string) $this->handle($action, $method, $path, $headers)->getBody();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $body
     */
    private function handle(string $action, string $method, string $path, array $headers = [], array $body = []): ResponseInterface
    {
        return $this->admin->controller->{$action}($this->admin->request($method, $path, $headers, $body));
    }
}
