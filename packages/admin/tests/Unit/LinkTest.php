<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Link;
use Hydra\Admin\Screens\LinkCountsScreen;
use Hydra\Admin\Tests\Support\ArraySource;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The declaration half of a filter link: what a module writes, what the URL
 * ends up carrying, and the two ways a bar of links is wrong before it renders.
 */
#[CoversClass(Link::class)]
#[CoversClass(LinkCountsScreen::class)]
final class LinkTest extends TestCase
{
    public function test_the_key_is_the_label_made_url_safe(): void
    {
        $this->assertSame('open', Link::make('Open')->key());
        $this->assertSame('needs-review', Link::make('Needs review')->key());
        $this->assertSame('on-hold', Link::make('  On Hold!  ')->key());
    }

    public function test_a_key_can_be_pinned_so_a_rewording_does_not_move_the_url(): void
    {
        $link = Link::make('Open')->where('status', 'open')->keyed('unresolved');

        $this->assertSame('unresolved', $link->key());
        $this->assertSame('Open', $link->label());
        $this->assertSame(['status' => 'open'], $link->filters());
    }

    public function test_a_link_with_nothing_to_live_at_is_refused(): void
    {
        $this->expectException(LogicException::class);

        Link::make('Open')->keyed('');
    }

    public function test_where_is_additive_and_leaves_the_link_it_was_called_on_alone(): void
    {
        $open = Link::make('Open')->where('status', 'open');
        $mine = $open->where('assignee', 'me');

        $this->assertSame(['status' => 'open'], $open->filters());
        $this->assertSame(['status' => 'open', 'assignee' => 'me'], $mine->filters());
    }

    public function test_declaring_links_routes_the_tally_they_need(): void
    {
        $blueprint = $this->module(Link::make('Open')->where('status', 'open'))->compile();

        $this->assertInstanceOf(LinkCountsScreen::class, $blueprint->screen('counts'));
        $this->assertSame('GET', $blueprint->screen('counts')?->method());
    }

    public function test_a_module_with_no_links_is_not_given_a_route_for_them(): void
    {
        $blueprint = Definition::make('tickets')
            ->source(ArraySource::class)
            ->fields(Field::id())
            ->compile();

        $this->assertNull($blueprint->screen('counts'));
    }

    public function test_two_links_at_one_key_are_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('two filter links keyed "open"');

        $this->module(
            Link::make('Open')->where('status', 'open'),
            Link::make('Open')->where('status', 'unresolved'),
        )->compile();
    }

    public function test_links_with_no_source_to_filter_are_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('filter links with no source');

        Definition::make('tickets')
            ->links(Link::make('Open')->where('status', 'open'))
            ->fields(Field::id())
            ->compile();
    }

    private function module(Link ...$links): Definition
    {
        return Definition::make('tickets')
            ->source(ArraySource::class)
            ->links(...$links)
            ->fields(Field::id());
    }
}
