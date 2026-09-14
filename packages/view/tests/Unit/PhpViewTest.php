<?php

declare(strict_types=1);

namespace Hydra\View\Tests\Unit;

use Hydra\Core\Security\Signer;
use Hydra\Csrf\CsrfGuard;
use Hydra\Session\Stores\ArraySessionStore;
use Hydra\View\HtmlView;
use Hydra\Http\CspNonce;
use Hydra\View\PhpView;
use Hydra\View\Contracts\ViewInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;
use RuntimeException;

/**
 * PhpView against real files in a scratch directory: rendering and escaping, the
 * fallback search path, and containment of the template name, which is the only
 * place an untrusted string reaches the filesystem.
 */
#[CoversClass(PhpView::class)]
final class PhpViewTest extends TestCase
{
    private string $root;
    private string $dir;
    private PhpView $view;

    protected function setUp(): void
    {
        // The view base path is a subdirectory of a scratch root so the
        // traversal tests have a real, existing PHP file one level up
        // ('../secret') to try to escape to, proving containment rather than
        // just "file didn't exist".
        $this->root = sys_get_temp_dir() . '/hydra-views-' . uniqid('', true);
        $this->dir = $this->root . '/views';
        mkdir($this->dir, 0777, true);
        file_put_contents($this->root . '/secret.php', '<?php echo "TOP-SECRET";');
        $this->view = new PhpView($this->dir, new CspNonce);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeTemplate(string $name, string $contents): void
    {
        $this->writeTo($this->dir, $name, $contents);
    }

    private function writeTo(string $dir, string $name, string $contents): void
    {
        $path = $dir . '/' . $name . '.php';
        $subdir = dirname($path);
        if (!is_dir($subdir)) {
            mkdir($subdir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * A second views directory standing in for one a package ships, sitting
     * beside the base path so both are one level under the scratch root.
     */
    private function packageDir(): string
    {
        $dir = $this->root . '/package-views';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    private function viewWithFallback(): PhpView
    {
        return new PhpView($this->dir, new CspNonce, fallbacks: [$this->packageDir()]);
    }

    public function test_is_view_interface(): void
    {
        $this->assertInstanceOf(ViewInterface::class, $this->view);
    }

    public function test_renders_template_with_data(): void
    {
        $this->writeTemplate('hello', 'Hello, <?= $this->e($name) ?>!');

        $this->assertSame('Hello, Will!', $this->view->render('hello', ['name' => 'Will']));
    }

    public function test_e_helper_escapes_untrusted_data(): void
    {
        $this->writeTemplate('x', '<?= $this->e($input) ?>');

        $out = $this->view->render('x', ['input' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_escapes_quotes(): void
    {
        $this->writeTemplate('x', '<?= $this->e($input) ?>');

        $out = $this->view->render('x', ['input' => '"hi" \'there\'']);

        $this->assertStringNotContainsString('"', $out);
        $this->assertStringContainsString('&quot;', $out);
        $this->assertStringContainsString('&#039;', $out);
    }

    public function test_html_instance_passes_through_unescaped(): void
    {
        $this->writeTemplate('x', '<?= $this->e($markup) ?>');

        $out = $this->view->render('x', ['markup' => new HtmlView('<b>bold</b>')]);

        // Explicitly-trusted markup is the ONLY way raw HTML reaches output.
        $this->assertSame('<b>bold</b>', $out);
    }

    public function test_non_html_stringable_is_escaped(): void
    {
        // A domain value object (Money, Uuid, ...) is untrusted like any string:
        // the safe path escapes it instead of throwing a TypeError.
        $this->writeTemplate('x', '<?= $this->e($value) ?>');

        $value = new class implements \Stringable {
            public function __toString(): string
            {
                return '<b>5 & 6</b>';
            }
        };

        $out = $this->view->render('x', ['value' => $value]);

        $this->assertSame('&lt;b&gt;5 &amp; 6&lt;/b&gt;', $out);
    }

    public function test_html_wraps_a_stringable_unescaped(): void
    {
        $this->writeTemplate('x', '<?= $this->e($markup) ?>');

        $inner = new class implements \Stringable {
            public function __toString(): string
            {
                return '<i>raw</i>';
            }
        };

        $out = $this->view->render('x', ['markup' => new HtmlView($inner)]);

        $this->assertSame('<i>raw</i>', $out);
    }

    public function test_renders_partial_via_this(): void
    {
        $this->writeTemplate('partial', 'Hi <?= $this->e($name) ?>');
        $this->writeTemplate('page', 'A: <?= $this->partial("partial", ["name" => $name]) ?>');

        $this->assertSame('A: Hi Will', $this->view->render('page', ['name' => 'Will']));
    }

    public function test_partial_ignores_a_stray_extends(): void
    {
        // A partial renders as a bare fragment: an extends() inside it must not
        // wrap the partial in a layout.
        $this->writeTemplate('wrap', '<body><?= $this->section("content") ?></body>');
        $this->writeTemplate('partial', '<?php $this->extends("wrap") ?>FRAG');
        $this->writeTemplate('page', '[<?= $this->partial("partial") ?>]');

        $this->assertSame('[FRAG]', $this->view->render('page'));
    }

    public function test_missing_template_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->view->render('does-not-exist');
    }

    public function test_a_template_only_a_fallback_has_is_still_found(): void
    {
        $view = $this->viewWithFallback();
        $this->writeTo($this->packageDir(), 'admin/table', 'PACKAGE');

        $this->assertSame('PACKAGE', $view->render('admin/table'));
    }

    public function test_the_base_path_wins_over_a_fallback(): void
    {
        // The override contract: an application replaces a package's template
        // by putting a file of the same name in its own views directory.
        $view = $this->viewWithFallback();
        $this->writeTo($this->packageDir(), 'admin/table', 'PACKAGE');
        $this->writeTemplate('admin/table', 'MINE');

        $this->assertSame('MINE', $view->render('admin/table'));
    }

    public function test_an_overridden_template_can_still_reach_the_ones_it_did_not_override(): void
    {
        // Each name resolves on its own, so a chain crosses freely between the
        // two directories. That is the point of overriding one template and
        // not the rest.
        $view = $this->viewWithFallback();
        $this->writeTo($this->packageDir(), 'admin/screen', 'pkg screen');
        $this->writeTo($this->packageDir(), 'admin/table', '[<?= $this->partial("admin/screen") ?>]');
        $this->writeTemplate('admin/table', 'MINE: <?= $this->partial("admin/screen") ?>');

        $this->assertSame('MINE: pkg screen', $view->render('admin/table'));
    }

    public function test_a_template_no_directory_has_is_reported_as_missing(): void
    {
        $view = $this->viewWithFallback();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does-not-exist');

        $view->render('does-not-exist');
    }

    public function test_a_fallback_does_not_widen_what_each_directory_contains(): void
    {
        // Both directories sit beside secret.php, and neither may be climbed
        // out of to reach it: a second search path is not a second chance.
        $view = $this->viewWithFallback();

        $this->expectException(RuntimeException::class);

        $view->render('../secret');
    }

    public function test_renders_template_in_a_subdirectory(): void
    {
        $this->writeTemplate('admin/users', 'Users: <?= $this->e($count) ?>');

        $this->assertSame('Users: 3', $this->view->render('admin/users', ['count' => 3]));
    }

    public function test_renders_a_deeply_nested_template(): void
    {
        // The containment check must not penalize legitimate nesting: a name
        // with several path segments resolves and renders like any other.
        $this->writeTemplate('sub/dir/template', 'Deep: <?= $this->e($n) ?>');

        $this->assertSame('Deep: 7', $this->view->render('sub/dir/template', ['n' => 7]));
    }

    public function test_traversal_that_stays_inside_the_root_still_renders(): void
    {
        // '../' is only dangerous when it escapes the view root. A name whose
        // '..' segments collapse back to a file still under the root is a
        // legitimate resolution: realpath() normalizes 'sub/../real' to
        // 'real', which sits inside the root, so the current code renders it.
        // (Documents intended behavior: containment is about the resolved
        // location, not the presence of '..' in the raw name.)
        mkdir($this->dir . '/sub');
        $this->writeTemplate('real', 'INSIDE');

        $this->assertSame('INSIDE', $this->view->render('sub/../real'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function traversalTemplateNames(): array
    {
        return [
            'parent directory'            => ['../secret'],
            'deep traversal'              => ['../../etc/passwd'],
            'traversal behind real prefix' => ['admin/../../secret'],
            'absolute path'               => ['/etc/passwd'],
        ];
    }

    #[DataProvider('traversalTemplateNames')]
    public function test_traversal_is_rejected_without_executing_or_leaking_the_path(string $template): void
    {
        // 'admin/../../secret' needs the intermediate directory to exist,
        // otherwise realpath() fails for the wrong reason and the test would
        // not exercise the containment check.
        mkdir($this->dir . '/admin');

        try {
            $this->view->render($template);
            $this->fail('expected traversal to be rejected');
        } catch (RuntimeException $e) {
            // Same exception as a plain miss, the escaped-to file was never
            // include()d, and no absolute filesystem path is disclosed.
            $this->assertStringNotContainsString('TOP-SECRET', $e->getMessage());
            $this->assertStringNotContainsString($this->root, $e->getMessage());
        }
    }

    public function test_traversal_to_an_existing_file_is_indistinguishable_from_a_miss(): void
    {
        // '../secret.php' exists, '../absent.php' does not; the messages must
        // match so a probe cannot use the renderer as a file-exists oracle.
        $messageFor = function (string $template): string {
            try {
                $this->view->render($template);
                $this->fail('expected a RuntimeException');
            } catch (RuntimeException $e) {
                return str_replace($template, '', $e->getMessage());
            }
        };

        $this->assertSame($messageFor('../secret'), $messageFor('../absent'));
    }

    public function test_null_byte_in_template_name_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->view->render("hello\0../secret");
    }

    public function test_internal_variable_names_are_not_clobbered_by_data(): void
    {
        // Data keyed like the renderer's internals must not break rendering.
        $this->writeTemplate('x', 'ok');

        $this->assertSame('ok', $this->view->render('x', ['__path' => 'evil', '__data' => 'evil']));
    }

    public function test_output_buffer_is_cleaned_when_template_throws(): void
    {
        $this->writeTemplate('boom', 'partial<?php throw new \RuntimeException("boom"); ?>');

        $level = ob_get_level();
        try {
            $this->view->render('boom');
            $this->fail('expected the template exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame($level, ob_get_level(), 'no leaked output buffer');
    }

    public function test_site_url_returns_the_path_unchanged_without_a_base_url(): void
    {
        // The isolation default: no base URL was wired, so siteUrl is a no-op.
        $this->writeTemplate('x', '<?= $this->siteUrl("/blog") ?>');

        $this->assertSame('/blog', $this->view->render('x'));
    }

    public function test_site_url_builds_an_absolute_url_from_the_base_url(): void
    {
        // A trailing slash on the base URL must not double up against the path.
        $view = new PhpView($this->dir, new CspNonce, null, 'https://example.com/');
        $this->writeTemplate('x', '<?= $this->siteUrl("/blog") ?>|<?= $this->siteUrl() ?>');

        $this->assertSame('https://example.com/blog|https://example.com', $view->render('x'));
    }

    public function test_csrf_helpers_throw_when_no_guard_is_configured(): void
    {
        $this->writeTemplate('x', '<?= $this->csrfToken() ?>');

        $this->expectException(RuntimeException::class);
        $this->view->render('x');
    }

    public function test_csrf_renders_a_hidden_field_with_the_session_token(): void
    {
        $store = new ArraySessionStore;
        $store->start();
        $guard = new CsrfGuard($store, Signer::fromHex(str_repeat('ab', 32)));
        $view = new PhpView($this->dir, new CspNonce, $guard);
        $this->writeTemplate('form', '<?= $this->csrf() ?>');

        $out = $view->render('form');

        $this->assertStringContainsString('name="' . CsrfGuard::FIELD . '"', $out);
        $this->assertStringContainsString('value="' . $guard->token() . '"', $out);
    }

    /**
     * The nonce is not optional. A view built without one used to throw the
     * first time a template asked for it, which put the failure on whoever
     * opened the screen rather than on whoever wired the view up.
     */
    public function test_a_view_cannot_be_built_without_a_nonce(): void
    {
        $constructor = (new ReflectionClass(PhpView::class))->getConstructor();
        $nonce = $constructor?->getParameters()[1] ?? null;

        $this->assertInstanceOf(ReflectionParameter::class, $nonce);
        $this->assertSame('cspNonce', $nonce->getName());
        $this->assertFalse($nonce->isOptional(), 'the nonce has gone back to being optional');
    }

    public function test_csp_nonce_renders_the_requests_token(): void
    {
        $nonce = new CspNonce;
        $view = new PhpView($this->dir, $nonce);
        $this->writeTemplate('x', '<?= $this->cspNonce() ?>');

        $this->assertSame($nonce->value(), $view->render('x'));
    }

    public function test_every_template_in_one_render_sees_the_same_nonce(): void
    {
        // The page stamps it in several places and a fragment swapped into that
        // page has to match; two tokens in one render would block one of them.
        $view = new PhpView($this->dir, new CspNonce, fallbacks: []);
        $this->writeTemplate('partial', '<?= $this->cspNonce() ?>');
        $this->writeTemplate('x', '<?= $this->cspNonce() ?>|<?= $this->partial("partial") ?>');

        [$outer, $inner] = explode('|', $view->render('x'));

        $this->assertSame($outer, $inner);
    }
}
