<?php

declare(strict_types=1);

namespace Hydra\View\Testing;

use Hydra\View\Contracts\ViewInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The behaviour every view owes its callers, published so an application that
 * puts a different engine behind the seam can be run against it.
 *
 * The seam exists so a controller never touches the filesystem or the engine,
 * which means the controller also cannot check what it is getting: it hands
 * over a name and an array and trusts what comes back. Two of the things it
 * trusts are worth stating outright — that a name it did not choose cannot
 * reach a file outside the template root, and that data from one render is gone
 * by the next.
 *
 * A view is asked for three templates by name. What they contain is the
 * subclass's to write, in whatever language its engine speaks; what they must
 * produce is stated on each hook.
 */
abstract class ViewContractTestCase extends TestCase
{
    /**
     * A view that can resolve exactly three template names:
     *
     *  - `plain`, rendering the text `PLAIN` and nothing else;
     *  - `greeting`, rendering `Hello ` followed by the `name` value from the
     *    render data, escaped for HTML, and rendering `Hello ` alone when no
     *    `name` was given;
     *  - `wrapped`, whose own body is `BODY` and which extends a layout that
     *    surrounds it with `[` and `]`.
     *
     * No other name resolves; `absent` in particular must not.
     */
    abstract protected function view(): ViewInterface;

    public function test_it_renders_a_template(): void
    {
        $this->assertSame('PLAIN', trim($this->view()->render('plain')));
    }

    public function test_it_renders_the_data_it_was_given(): void
    {
        $this->assertStringContainsString('Ada', $this->view()->render('greeting', ['name' => 'Ada']));
    }

    public function test_data_does_not_survive_into_the_next_render(): void
    {
        // One view instance serves every request in a long-running worker, and
        // a leak here shows one visitor's name to the next.
        $view = $this->view();
        $view->render('greeting', ['name' => 'Ada']);

        $this->assertStringNotContainsString('Ada', $view->render('greeting'));
    }

    public function test_data_is_escaped_on_its_way_into_the_page(): void
    {
        // Whether the engine escapes by default or the template asks it to, the
        // seam's promise is HTML, and a value reaching it came from a request.
        $html = $this->view()->render('greeting', ['name' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_layout_wraps_the_template_by_default(): void
    {
        $html = $this->view()->render('wrapped');

        $this->assertStringContainsString('[', $html);
        $this->assertStringContainsString('BODY', $html);
    }

    public function test_the_body_renders_alone_when_the_layout_is_declined(): void
    {
        // What an htmx fragment response wants: the same template, no chrome.
        $html = $this->view()->render('wrapped', layout: false);

        $this->assertStringContainsString('BODY', $html);
        $this->assertStringNotContainsString('[', $html);
    }

    public function test_has_answers_for_a_template_that_resolves(): void
    {
        $this->assertTrue($this->view()->has('plain'));
    }

    public function test_has_answers_for_one_that_does_not(): void
    {
        // false, not an exception: has() is for asking ahead of time, and a
        // caller that had to catch to find out would just call render().
        $this->assertFalse($this->view()->has('absent'));
    }

    public function test_rendering_a_template_that_does_not_resolve_throws(): void
    {
        $this->expectException(Throwable::class);
        $this->view()->render('absent');
    }

    /** @return array<string, array{string}> */
    public static function namesThatEscapeTheTemplateRoot(): array
    {
        return [
            'parent directory' => ['../secret'],
            'deep traversal' => ['../../etc/passwd'],
            'traversal behind a real prefix' => ['admin/../../secret'],
            'absolute path' => ['/etc/passwd'],
            'null byte' => ["plain\0../secret"],
        ];
    }

    #[DataProvider('namesThatEscapeTheTemplateRoot')]
    public function test_a_name_that_escapes_the_template_root_does_not_render(string $template): void
    {
        // A template name is routinely built from a route parameter or a
        // configured module, so it is attacker-influenced often enough to
        // matter. Refusing is the whole requirement; how is the engine's.
        $this->expectException(Throwable::class);
        $this->view()->render($template);
    }

    #[DataProvider('namesThatEscapeTheTemplateRoot')]
    public function test_has_reports_such_a_name_as_missing_rather_than_throwing(string $template): void
    {
        $this->assertFalse($this->view()->has($template));
    }
}
