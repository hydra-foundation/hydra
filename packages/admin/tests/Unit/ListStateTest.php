<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Criteria;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\ArrayWritableSource;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\Tests\Support\LinkedWritableModule;
use Hydra\Admin\ViewModels\FormViewModel;
use Hydra\Admin\ViewModels\ListViewModel;
use Hydra\Admin\ViewModels\ShowViewModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * What the visitor had the table narrowed to, on the way into a row and back
 * out of it.
 *
 * A row lives at a URL of its own, and for as long as that URL said nothing
 * about the table it was reached through, opening a row threw the view away:
 * Back, Cancel and the list a save returns to all landed on the module's
 * defaults, so narrowing a list to one filter link and editing a row from it
 * put the visitor back on "All".
 */
#[CoversClass(AdminController::class)]
#[CoversClass(Criteria::class)]
#[CoversClass(ListViewModel::class)]
#[CoversClass(ShowViewModel::class)]
#[CoversClass(FormViewModel::class)]
final class ListStateTest extends TestCase
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

    public function test_a_narrowed_list_puts_its_view_on_every_row_it_offers(): void
    {
        $body = $this->render('list', 'GET', '/admin/users?q=ada&sort=username&dir=desc');

        $this->assertStringContainsString('/admin/users/1?q=ada&amp;sort=username&amp;dir=desc', $body);
        $this->assertStringContainsString('/admin/users/1/edit?q=ada&amp;sort=username&amp;dir=desc', $body);
        $this->assertStringContainsString('/admin/users/1/delete?q=ada&amp;sort=username&amp;dir=desc', $body);
        $this->assertStringContainsString('/admin/users/new?q=ada&amp;sort=username&amp;dir=desc', $body);
    }

    public function test_a_list_nobody_has_narrowed_leaves_its_row_urls_bare(): void
    {
        $body = $this->render('list', 'GET', '/admin/users');

        $this->assertStringContainsString('"/admin/users/1/edit"', $body);
        $this->assertStringNotContainsString('/admin/users/1/edit?', $body);
    }

    public function test_cancel_returns_to_the_list_the_form_was_opened_from(): void
    {
        $body = $this->render('edit', 'GET', '/admin/users/1/edit?page=2&sort=username&dir=desc');

        $this->assertStringContainsString('"/admin/users?sort=username&amp;dir=desc&amp;page=2"', $body);
    }

    public function test_back_returns_to_the_list_the_row_was_opened_from(): void
    {
        $body = $this->render('show', 'GET', '/admin/users/2?q=ada');

        // Back, and the ways on from here, which have to carry it too: editing
        // a row reached this way and cancelling must not lose the search either.
        $this->assertStringContainsString('"/admin/users?q=ada&amp;sort=id&amp;dir=asc"', $body);
        $this->assertStringContainsString('/admin/users/2/edit?q=ada&amp;sort=id&amp;dir=asc', $body);
    }

    public function test_a_save_returns_to_the_list_the_edit_was_made_from(): void
    {
        $response = $this->handle(
            'update',
            'POST',
            '/admin/users/1/edit?q=ada',
            $this->admin->body(),
            ['username' => 'ada lovelace'],
        );

        $this->assertSame('/admin/users?q=ada&sort=id&dir=asc', $this->pushed($response));
    }

    public function test_a_save_without_htmx_redirects_to_the_list_it_was_made_from(): void
    {
        // Nothing reports the page the browser is on, so the action the form
        // posted to is the only account of it — which is why it carries one.
        $response = $this->handle('update', 'POST', '/admin/users/1/edit?q=ada', [], ['username' => 'ada lovelace']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/users?q=ada&sort=id&dir=asc', $response->getHeaderLine('Location'));
    }

    public function test_a_rejected_save_comes_back_with_the_list_still_on_it(): void
    {
        $response = $this->handle('update', 'POST', '/admin/users/1/edit?q=ada', [], ['username' => '']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('"/admin/users?q=ada&amp;sort=id&amp;dir=asc"', (string) $response->getBody());
    }

    public function test_a_created_row_opens_with_the_list_it_was_created_from(): void
    {
        $response = $this->handle('store', 'POST', '/admin/users/new?q=ada', [], ['username' => 'linus']);

        $this->assertSame('/admin/users/6?q=ada&sort=id&dir=asc', $response->getHeaderLine('Location'));
    }

    public function test_a_delete_returns_to_the_list_the_row_was_deleted_from(): void
    {
        $response = $this->handle('destroy', 'POST', '/admin/users/1/delete?q=ada', $this->admin->body());

        $this->assertSame('/admin/users?q=ada&sort=id&dir=asc', $this->pushed($response));
    }

    public function test_a_filter_link_survives_an_edit(): void
    {
        $admin = new AdminHarness(
            [LinkedWritableModule::class => new LinkedWritableModule, ArrayWritableSource::class => new ArrayWritableSource],
            [LinkedWritableModule::class],
        );

        $list = (string) $admin->controller->list($admin->request('GET', '/admin/users?view=ada'))->getBody();
        $this->assertStringContainsString('/admin/users/1/edit?view=ada', $list);

        $form = (string) $admin->controller->edit($admin->request('GET', '/admin/users/1/edit?view=ada'))->getBody();
        $this->assertStringContainsString('"/admin/users?view=ada"', $form);
    }

    /**
     * The list a delete button in the table posts from. It is the one write
     * whose URL is a row's while the browser is on the list, and the two agree,
     * so it makes no difference which is read — but it is the case the old
     * behaviour was built around and must not have broken.
     */
    public function test_a_delete_from_the_table_still_reads_the_page_it_was_made_from(): void
    {
        $response = $this->handle(
            'destroy',
            'POST',
            '/admin/users/1/delete',
            [...$this->admin->body(), 'HX-Current-URL' => '/admin/users?q=ada'],
        );

        $this->assertSame('/admin/users?q=ada&sort=id&dir=asc', $this->pushed($response));
    }

    /** Where the response tells htmx to put the browser once it has swapped. */
    private function pushed(ResponseInterface $response): ?string
    {
        preg_match('/data-hydra-push-url="([^"]*)"/', (string) $response->getBody(), $matches);

        return isset($matches[1]) ? htmlspecialchars_decode($matches[1], ENT_QUOTES) : null;
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
