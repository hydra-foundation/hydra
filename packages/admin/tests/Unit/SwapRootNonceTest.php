<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use DOMDocument;
use DOMElement;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CountPresenter;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\Tests\Support\TicketSource;
use Hydra\Admin\Tests\Support\TicketsModule;
use Hydra\Admin\Tests\Support\WidgetDashboardModule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every element htmx swaps in has to be vouched for.
 *
 * The CSP extension gates each root of a swapped fragment and refuses any that
 * does not carry the page nonce, whether or not it asks for anything itself —
 * an element boosted from an ancestor has no hx- attributes of its own and must
 * still be gated. A root that arrives without one is therefore stripped and
 * logged, once per root per swap, and the failure is silent enough to live in
 * the console for a long time: nothing renders wrong, there is just a refusal
 * behind every screen.
 *
 * This asserts the whole surface at once because the alternative is remembering
 * it in each template, which is exactly what went wrong: the nonce was written
 * beside the hx-get on two of them, so the copies that came back with nothing
 * left to fetch came back unvouched.
 */
#[CoversNothing]
final class SwapRootNonceTest extends TestCase
{
    /**
     * htmx reads a fragment's <head> to retitle the page and never gates it, so
     * it is the one root that needs no nonce.
     */
    private const UNGATED = ['head'];

    public function test_a_list_body_swap_vouches_for_every_root(): void
    {
        $admin = $this->tickets();

        $this->assertAllNonced($admin->controller->list(
            $admin->request('GET', '/admin/tickets', $admin->body()),
        ));
    }

    public function test_a_list_frame_swap_vouches_for_every_root(): void
    {
        $admin = $this->tickets();

        $this->assertAllNonced($admin->controller->list(
            $admin->request('GET', '/admin/tickets', $admin->frame()),
        ));
    }

    public function test_the_tallies_fragment_vouches_for_itself(): void
    {
        // The bar that comes back has nothing left to fetch, which is when it
        // used to arrive without a nonce.
        $admin = $this->tickets();

        $this->assertAllNonced($admin->controller->counts(
            $admin->request('GET', '/admin/tickets/counts'),
        ));
    }

    public function test_a_dashboard_frame_swap_vouches_for_every_root(): void
    {
        $admin = $this->dashboard();

        $this->assertAllNonced($admin->controller->dashboard(
            $admin->request('GET', '/admin/overview', $admin->frame()),
        ));
    }

    public function test_a_dashboard_body_swap_vouches_for_every_root(): void
    {
        // What changing the period asks for. At this depth the grid and the
        // control are roots in their own right rather than nested inside one,
        // which is how both came to be swapped in unvouched.
        $admin = $this->dashboard();

        $this->assertAllNonced($admin->controller->dashboard(
            $admin->request('GET', '/admin/overview?period=month', $admin->body()),
        ));
    }

    public function test_a_filled_card_vouches_for_itself(): void
    {
        // The card that does not poll, which is the one that stops carrying an
        // hx-get once it is full.
        $admin = $this->dashboard();

        $this->assertAllNonced($admin->controller->widget(
            $admin->request('GET', '/admin/overview/w/accounts'),
        ));
    }

    public function test_a_polling_card_vouches_for_itself(): void
    {
        $admin = $this->dashboard();

        $this->assertAllNonced($admin->controller->widget(
            $admin->request('GET', '/admin/overview/w/live'),
        ));
    }

    public function test_a_form_swap_vouches_for_every_root(): void
    {
        $admin = $this->crud();

        $this->assertAllNonced($admin->controller->create(
            $admin->request('GET', '/admin/users/new', $admin->frame()),
        ));
    }

    public function test_a_row_swap_vouches_for_every_root(): void
    {
        $admin = $this->crud();

        $this->assertAllNonced($admin->controller->show(
            $admin->request('GET', '/admin/users/1', $admin->frame()),
        ));
    }

    public function test_a_rejected_submission_vouches_for_every_root(): void
    {
        $admin = $this->crud();

        $this->assertAllNonced($admin->controller->store(
            $admin->request('POST', '/admin/users/new', $admin->frame(), ['username' => '']),
        ));
    }

    public function test_the_list_a_write_lands_on_vouches_for_every_root(): void
    {
        // What the visitor is actually looking at after creating a row, and the
        // one path none of the above goes down.
        $admin = $this->crud();

        $this->assertAllNonced($admin->controller->store(
            $admin->request('POST', '/admin/users/new', $admin->frame(), ['username' => 'linus']),
        ));
    }

    private function assertAllNonced(object $response): void
    {
        $html = (string) $response->getBody();
        $missing = [];

        foreach ($this->roots($html) as $root) {
            if (in_array($root->tagName, self::UNGATED, true) || $root->hasAttribute('hx-nonce')) {
                continue;
            }

            $id = $root->getAttribute('id');
            $missing[] = $root->tagName . ($id === '' ? '' : '#' . $id);
        }

        $this->assertSame([], $missing, 'swapped roots arriving without hx-nonce');
    }

    /** @return list<DOMElement> */
    private function roots(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="roots">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $roots = [];

        foreach ($document->getElementById('roots')->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $roots[] = $node;
            }
        }

        return $roots;
    }

    private function crud(): AdminHarness
    {
        return new AdminHarness(
            [CrudUsersModule::class => new CrudUsersModule, CrudUserSource::class => new CrudUserSource],
            [CrudUsersModule::class],
        );
    }

    private function tickets(): AdminHarness
    {
        return new AdminHarness(
            [TicketsModule::class => new TicketsModule, TicketSource::class => new TicketSource],
            [TicketsModule::class],
        );
    }

    private function dashboard(): AdminHarness
    {
        return new AdminHarness(
            [
                WidgetDashboardModule::class => new WidgetDashboardModule,
                CountPresenter::class => new CountPresenter,
            ],
            [WidgetDashboardModule::class],
        );
    }
}
