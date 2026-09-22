<?php

declare(strict_types=1);

namespace Hydra\View\Tests\Unit;

use Hydra\View\Contracts\ViewInterface;
use Hydra\View\Testing\FakeView;
use Hydra\View\Testing\ViewContractTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The fake against the contract PhpView answers, plus the recording it exists
 * for. The escaping the contract asks for is the template's here, as it is in
 * any engine that does not escape by default.
 */
#[CoversClass(FakeView::class)]
final class FakeViewTest extends ViewContractTestCase
{
    protected function view(): ViewInterface
    {
        return (new FakeView)
            ->define('plain', 'PLAIN')
            ->define('greeting', static fn (array $data): string => 'Hello ' . htmlspecialchars((string) ($data['name'] ?? '')))
            ->define('wrapped', 'BODY', static fn (string $body): string => "[{$body}]");
    }

    public function test_an_undefined_template_names_itself_in_the_failure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The template posts/idnex was not defined on this FakeView.');
        (new FakeView)->define('posts/index')->render('posts/idnex');
    }

    public function test_a_template_defined_without_a_body_renders_empty(): void
    {
        $this->assertSame('', (new FakeView)->define('posts/index')->render('posts/index'));
    }

    public function test_it_records_every_render_in_order(): void
    {
        $view = (new FakeView)->define('a')->define('b');
        $view->render('a', ['x' => 1]);
        $view->render('b', layout: false);

        $this->assertSame([
            ['template' => 'a', 'data' => ['x' => 1], 'layout' => true],
            ['template' => 'b', 'data' => [], 'layout' => false],
        ], $view->renders());
    }

    public function test_a_failed_render_is_not_recorded(): void
    {
        $view = new FakeView;

        try {
            $view->render('absent');
        } catch (InvalidArgumentException) {
        }

        $view->assertNothingRendered();
    }

    public function test_assert_rendered_matches_the_given_keys_and_ignores_the_rest(): void
    {
        $view = (new FakeView)->define('posts/show');
        $view->render('posts/show', ['post' => 'hello', 'comments' => []]);

        $view->assertRendered('posts/show');
        $view->assertRendered('posts/show', ['post' => 'hello']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The template posts/show was never rendered with that data.');
        $view->assertRendered('posts/show', ['post' => 'goodbye']);
    }

    public function test_assert_rendered_compares_values_strictly(): void
    {
        $view = (new FakeView)->define('posts/show');
        $view->render('posts/show', ['id' => '7']);

        $this->expectException(AssertionFailedError::class);
        $view->assertRendered('posts/show', ['id' => 7]);
    }

    public function test_assert_rendered_fails_for_a_template_never_rendered(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The template posts/show was never rendered.');
        (new FakeView)->assertRendered('posts/show');
    }

    public function test_assert_rendered_fragment_asks_about_the_layout(): void
    {
        $view = (new FakeView)->define('rows');
        $view->render('rows', layout: false);
        $view->assertRenderedFragment('rows');

        $view = (new FakeView)->define('rows');
        $view->render('rows');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The template rows was never rendered without its layout.');
        $view->assertRenderedFragment('rows');
    }

    public function test_assert_not_rendered(): void
    {
        $view = (new FakeView)->define('a');
        $view->assertNotRendered('a');
        $view->render('a');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The template a was rendered 1 times.');
        $view->assertNotRendered('a');
    }

    public function test_assert_nothing_rendered_fails_after_a_render(): void
    {
        $view = (new FakeView)->define('a');
        $view->render('a');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('1 templates were rendered.');
        $view->assertNothingRendered();
    }
}
