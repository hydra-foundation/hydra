<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Criteria;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Page;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\ViewModels\ListViewModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a table template reads: the sliding pager window, and the URLs behind the
 * page and sort links, which carry the current criteria rather than resetting it.
 */
final class ListViewModelTest extends TestCase
{
    /**
     * The window is what the pager renders, and a visitor walking a long table
     * should see it slide rather than grow and shrink. Read as: this many pages,
     * standing on this one, shows these numbers.
     *
     * @return array<string, array{int, int, list<int>}>
     */
    public static function pageWindows(): array
    {
        return [
            'a single page is the whole pager'   => [1, 1, [1]],
            'fewer pages than the window'        => [3, 2, [1, 2, 3]],
            'exactly the window'                 => [5, 3, [1, 2, 3, 4, 5]],
            'at the start, it cannot slide left' => [20, 1, [1, 2, 3, 4, 5]],
            'one in, still pinned to the start'  => [20, 2, [1, 2, 3, 4, 5]],
            'far enough in to centre'            => [20, 7, [5, 6, 7, 8, 9]],
            'one from the end'                   => [20, 19, [16, 17, 18, 19, 20]],
            'at the end, it cannot slide right'  => [20, 20, [16, 17, 18, 19, 20]],
        ];
    }

    /** @param list<int> $expected */
    #[DataProvider('pageWindows')]
    public function test_the_pager_keeps_its_width_as_it_slides(int $pages, int $current, array $expected): void
    {
        $this->assertSame($expected, $this->viewModel($pages, $current)->pageWindow());
    }

    public function test_a_narrower_window_is_narrower_everywhere(): void
    {
        $this->assertSame([6, 7, 8], $this->viewModel(20, 7)->pageWindow(radius: 1));
        $this->assertSame([1, 2, 3], $this->viewModel(20, 1)->pageWindow(radius: 1));
        $this->assertSame([18, 19, 20], $this->viewModel(20, 20)->pageWindow(radius: 1));
    }

    public function test_a_page_link_leaves_the_first_page_out_of_the_url(): void
    {
        // The first page is what the bare module URL already shows.
        $vm = $this->viewModel(20, 7);

        $this->assertSame('/admin/users?page=2', $vm->pageLink(2));
        $this->assertSame('/admin/users', $vm->pageLink(1));
    }

    public function test_sorting_by_the_current_column_turns_it_around(): void
    {
        $vm = $this->viewModel(20, 7, new Criteria(perPage: 10, sort: 'username', direction: 'asc'));
        [, $username] = $vm->columns();

        $this->assertSame('asc', $vm->sortedBy($username));
        $this->assertStringContainsString('dir=desc', $vm->sortLink($username));
    }

    public function test_sorting_starts_a_new_column_ascending_and_at_the_top(): void
    {
        // Page 3 of one ordering says nothing about the next; re-sorting drops it.
        $vm = $this->viewModel(20, 3, new Criteria(page: 3, perPage: 10, sort: 'username'));
        [$id] = $vm->columns();

        $this->assertNull($vm->sortedBy($id));
        $this->assertStringContainsString('dir=asc', $vm->sortLink($id));
        $this->assertStringNotContainsString('page=', $vm->sortLink($id));
    }

    private function viewModel(int $pages, int $current, ?Criteria $criteria = null): ListViewModel
    {
        $perPage = 10;
        $criteria ??= new Criteria(page: $current, perPage: $perPage);

        return new ListViewModel(
            $this->blueprint(),
            new Page([], $pages * $perPage, $criteria->onPage($current)),
            '/admin',
        );
    }

    private function blueprint(): Blueprint
    {
        return Definition::make('users')
            ->source(new ArraySource)
            ->fields(
                Field::id()->sortable(),
                Field::text('username')->sortable(),
            )
            ->compile();
    }
}
