<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Sources\CallableSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The four-line way to declare a source. It is an adapter and nothing else, so
 * what matters is that the criteria arrive unchanged and the page comes back
 * unchanged: anything it added would be behaviour a module implementing the
 * interface directly would not get.
 */
#[CoversClass(CallableSource::class)]
final class CallableSourceTest extends TestCase
{
    public function test_it_is_a_source(): void
    {
        $this->assertInstanceOf(SourceInterface::class, new CallableSource($this->pager()));
    }

    public function test_it_hands_the_criteria_to_the_callable_untouched(): void
    {
        $seen = null;
        $source = new CallableSource(function (Criteria $criteria) use (&$seen): Page {
            $seen = $criteria;

            return new Page([], 0, $criteria);
        });

        $criteria = new Criteria(page: 3, perPage: 10, sort: 'name', direction: 'desc');
        $source->page($criteria);

        $this->assertSame($criteria, $seen);
    }

    public function test_it_returns_the_page_the_callable_built(): void
    {
        $page = new Page([['id' => 1]], 1, new Criteria);
        $source = new CallableSource(static fn (Criteria $c): Page => $page);

        $this->assertSame($page, $source->page(new Criteria));
    }

    public function test_it_accepts_any_callable_shape(): void
    {
        // first-class callable syntax in the constructor is what makes this
        // work for a method reference as well as a closure, and a module
        // written either way must behave the same.
        $source = new CallableSource($this->rows(...));

        $this->assertSame([['id' => 7]], $source->page(new Criteria)->rows);
    }

    public function test_it_is_called_once_per_page_request(): void
    {
        // Not memoised: a list screen re-reads on every request, and a source
        // that cached its first answer would show stale rows after a write.
        $calls = 0;
        $source = new CallableSource(function (Criteria $criteria) use (&$calls): Page {
            $calls++;

            return new Page([], 0, $criteria);
        });

        $source->page(new Criteria);
        $source->page(new Criteria);

        $this->assertSame(2, $calls);
    }

    private function rows(Criteria $criteria): Page
    {
        return new Page([['id' => 7]], 1, $criteria);
    }

    /** @return callable(Criteria): Page */
    private function pager(): callable
    {
        return static fn (Criteria $criteria): Page => new Page([], 0, $criteria);
    }
}
