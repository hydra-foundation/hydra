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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PhpViewTest extends TestCase
{
    private string $root;
    private string $dir;
    private PhpView $view;

    protected function setUp(): void
    {
        // The view base path is a subdirectory of a scratch root so the
        // traversal tests have a real, existing PHP file one level up
        // ('../secret') to try to escape to — proving containment, not just
        // "file didn't exist".
        $this->root = sys_get_temp_dir() . '/hydra-views-' . uniqid('', true);
        $this->dir = $this->root . '/views';
        mkdir($this->dir, 0777, true);
        file_put_contents($this->root . '/secret.php', '<?php echo "TOP-SECRET";');
        $this->view = new PhpView($this->dir);
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
        return new PhpView($this->dir, fallbacks: [$this->packageDir()]);
    }

    public function testIsViewInterface(): void
    {
        $this->assertInstanceOf(ViewInterface::class, $this->view);
    }

    public function testRendersTemplateWithData(): void
    {
        $this->writeTemplate('hello', 'Hello, <?= $this->e($name) ?>!');

        $this->assertSame('Hello, Will!', $this->view->render('hello', ['name' => 'Will']));
    }

    public function testEHelperEscapesUntrustedData(): void
    {
        $this->writeTemplate('x', '<?= $this->e($input) ?>');

        $out = $this->view->render('x', ['input' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testEscapesQuotes(): void
    {
        $this->writeTemplate('x', '<?= $this->e($input) ?>');

        $out = $this->view->render('x', ['input' => '"hi" \'there\'']);

        $this->assertStringNotContainsString('"', $out);
        $this->assertStringContainsString('&quot;', $out);
        $this->assertStringContainsString('&#039;', $out);
    }

    public function testHtmlInstancePassesThroughUnescaped(): void
    {
        $this->writeTemplate('x', '<?= $this->e($markup) ?>');

        $out = $this->view->render('x', ['markup' => new HtmlView('<b>bold</b>')]);

        // Explicitly-trusted markup is the ONLY way raw HTML reaches output.
        $this->assertSame('<b>bold</b>', $out);
    }

    public function testNonHtmlStringableIsEscaped(): void
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

    public function testHtmlWrapsAStringableUnescaped(): void
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

    public function testRendersPartialViaThis(): void
    {
        $this->writeTemplate('partial', 'Hi <?= $this->e($name) ?>');
        $this->writeTemplate('page', 'A: <?= $this->partial("partial", ["name" => $name]) ?>');

        $this->assertSame('A: Hi Will', $this->view->render('page', ['name' => 'Will']));
    }

    public function testPartialIgnoresAStrayExtends(): void
    {
        // A partial renders as a bare fragment: an extends() inside it must not
        // wrap the partial in a layout.
        $this->writeTemplate('wrap', '<body><?= $this->section("content") ?></body>');
        $this->writeTemplate('partial', '<?php $this->extends("wrap") ?>FRAG');
        $this->writeTemplate('page', '[<?= $this->partial("partial") ?>]');

        $this->assertSame('[FRAG]', $this->view->render('page'));
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->view->render('does-not-exist');
    }

    public function testATemplateOnlyAFallbackHasIsStillFound(): void
    {
        $view = $this->viewWithFallback();
        $this->writeTo($this->packageDir(), 'admin/table', 'PACKAGE');

        $this->assertSame('PACKAGE', $view->render('admin/table'));
    }

    public function testTheBasePathWinsOverAFallback(): void
    {
        // The override contract: an application replaces a package's template
        // by putting a file of the same name in its own views directory.
        $view = $this->viewWithFallback();
        $this->writeTo($this->packageDir(), 'admin/table', 'PACKAGE');
        $this->writeTemplate('admin/table', 'MINE');

        $this->assertSame('MINE', $view->render('admin/table'));
    }

    public function testAnOverriddenTemplateCanStillReachTheOnesItDidNotOverride(): void
    {
        // Each name resolves on its own, so a chain crosses freely between the
        // two directories — the point of overriding one template and not the rest.
        $view = $this->viewWithFallback();
        $this->writeTo($this->packageDir(), 'admin/screen', 'pkg screen');
        $this->writeTo($this->packageDir(), 'admin/table', '[<?= $this->partial("admin/screen") ?>]');
        $this->writeTemplate('admin/table', 'MINE: <?= $this->partial("admin/screen") ?>');

        $this->assertSame('MINE: pkg screen', $view->render('admin/table'));
    }

    public function testATemplateNoDirectoryHasIsReportedAsMissing(): void
    {
        $view = $this->viewWithFallback();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does-not-exist');

        $view->render('does-not-exist');
    }

    public function testAFallbackDoesNotWidenWhatEachDirectoryContains(): void
    {
        // Both directories sit beside secret.php, and neither may be climbed
        // out of to reach it — a second search path is not a second chance.
        $view = $this->viewWithFallback();

        $this->expectException(RuntimeException::class);

        $view->render('../secret');
    }

    public function testRendersTemplateInASubdirectory(): void
    {
        $this->writeTemplate('admin/users', 'Users: <?= $this->e($count) ?>');

        $this->assertSame('Users: 3', $this->view->render('admin/users', ['count' => 3]));
    }

    public function testRendersADeeplyNestedTemplate(): void
    {
        // The containment check must not penalize legitimate nesting: a name
        // with several path segments resolves and renders like any other.
        $this->writeTemplate('sub/dir/template', 'Deep: <?= $this->e($n) ?>');

        $this->assertSame('Deep: 7', $this->view->render('sub/dir/template', ['n' => 7]));
    }

    public function testTraversalThatStaysInsideTheRootStillRenders(): void
    {
        // '../' is only dangerous when it escapes the view root. A name whose
        // '..' segments collapse back to a file still under the root is a
        // legitimate resolution — realpath() normalizes 'sub/../real' to
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
    public function testTraversalIsRejectedWithoutExecutingOrLeakingThePath(string $template): void
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

    public function testTraversalToAnExistingFileIsIndistinguishableFromAMiss(): void
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

    public function testNullByteInTemplateNameIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->view->render("hello\0../secret");
    }

    public function testInternalVariableNamesAreNotClobberedByData(): void
    {
        // Data keyed like the renderer's internals must not break rendering.
        $this->writeTemplate('x', 'ok');

        $this->assertSame('ok', $this->view->render('x', ['__path' => 'evil', '__data' => 'evil']));
    }

    public function testOutputBufferIsCleanedWhenTemplateThrows(): void
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

    public function testSiteUrlReturnsThePathUnchangedWithoutABaseUrl(): void
    {
        // The isolation default: no base URL was wired, so siteUrl is a no-op.
        $this->writeTemplate('x', '<?= $this->siteUrl("/blog") ?>');

        $this->assertSame('/blog', $this->view->render('x'));
    }

    public function testSiteUrlBuildsAnAbsoluteUrlFromTheBaseUrl(): void
    {
        // A trailing slash on the base URL must not double up against the path.
        $view = new PhpView($this->dir, null, 'https://example.com/');
        $this->writeTemplate('x', '<?= $this->siteUrl("/blog") ?>|<?= $this->siteUrl() ?>');

        $this->assertSame('https://example.com/blog|https://example.com', $view->render('x'));
    }

    public function testCsrfHelpersThrowWhenNoGuardIsConfigured(): void
    {
        $this->writeTemplate('x', '<?= $this->csrfToken() ?>');

        $this->expectException(RuntimeException::class);
        $this->view->render('x');
    }

    public function testCsrfRendersAHiddenFieldWithTheSessionToken(): void
    {
        $store = new ArraySessionStore;
        $store->start();
        $guard = new CsrfGuard($store, Signer::fromHex(str_repeat('ab', 32)));
        $view = new PhpView($this->dir, $guard);
        $this->writeTemplate('form', '<?= $this->csrf() ?>');

        $out = $view->render('form');

        $this->assertStringContainsString('name="' . CsrfGuard::FIELD . '"', $out);
        $this->assertStringContainsString('value="' . $guard->token() . '"', $out);
    }

    public function testCspNonceThrowsWhenNoNonceIsConfigured(): void
    {
        $this->writeTemplate('x', '<?= $this->cspNonce() ?>');

        $this->expectException(RuntimeException::class);
        $this->view->render('x');
    }

    public function testCspNonceRendersTheRequestsToken(): void
    {
        $nonce = new CspNonce;
        $view = new PhpView($this->dir, cspNonce: $nonce);
        $this->writeTemplate('x', '<?= $this->cspNonce() ?>');

        $this->assertSame($nonce->value(), $view->render('x'));
    }

    public function testEveryTemplateInOneRenderSeesTheSameNonce(): void
    {
        // The page stamps it in several places and a fragment swapped into that
        // page has to match; two tokens in one render would block one of them.
        $view = new PhpView($this->dir, cspNonce: new CspNonce, fallbacks: []);
        $this->writeTemplate('partial', '<?= $this->cspNonce() ?>');
        $this->writeTemplate('x', '<?= $this->cspNonce() ?>|<?= $this->partial("partial") ?>');

        [$outer, $inner] = explode('|', $view->render('x'));

        $this->assertSame($outer, $inner);
    }
}
