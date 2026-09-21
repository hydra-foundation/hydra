<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\AssetController;
use Hydra\Http\CspNonce;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The stylesheet and script the package serves for its own templates. What is
 * worth asserting is mostly what it refuses: a name it does not know, and a
 * second request for one it does.
 */
#[CoversClass(AdminServiceProvider::class)]
#[CoversClass(AssetController::class)]
final class AssetControllerTest extends TestCase
{
    private AssetController $controller;

    protected function setUp(): void
    {
        $psr17 = new Psr17Factory;
        $this->controller = new AssetController(new Responder($psr17, $psr17, new CspNonce));
    }

    public function test_it_serves_the_stylesheet_the_shipped_templates_use(): void
    {
        $response = $this->get('stylesheet');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('.admin-frame', (string) $response->getBody());
    }

    public function test_it_serves_the_script_the_shipped_templates_use(): void
    {
        $response = $this->get('script');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('admin-toggle', (string) $response->getBody());
    }

    public function test_the_urls_carry_no_extension(): void
    {
        // The reason is a web server's, not a browser's: a ".css" under a path
        // with no file behind it is answered by nginx's own static rules
        // before PHP is reached. Naming one here would 404 in production and
        // pass every test.
        $this->expectException(NotFoundException::class);

        $this->get('admin.css');
    }

    public function test_a_name_it_does_not_know_is_not_a_path(): void
    {
        $this->expectException(NotFoundException::class);

        $this->get('../composer.json');
    }

    public function test_an_unchanged_asset_comes_back_as_a_304_with_no_body(): void
    {
        $etag = $this->get('stylesheet')->getHeaderLine('ETag');

        $this->assertNotSame('', $etag);

        $response = $this->get('stylesheet', $etag);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame($etag, $response->getHeaderLine('ETag'));
    }

    public function test_a_stale_validator_is_answered_with_the_asset(): void
    {
        $response = $this->get('stylesheet', '"0-0"');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame('', (string) $response->getBody());
    }

    public function test_the_asset_is_revalidated_rather_than_cached_outright(): void
    {
        // The URL carries no version, so a browser holding it for any length of
        // time is a browser that cannot be told the package was upgraded.
        $this->assertSame(
            'public, max-age=0, must-revalidate',
            $this->get('stylesheet')->getHeaderLine('Cache-Control'),
        );
    }

    private function get(string $asset, ?string $ifNoneMatch = null): ResponseInterface
    {
        $request = (new Psr17Factory)
            ->createServerRequest('GET', '/admin/assets/' . $asset)
            ->withAttribute('asset', $asset);

        if ($ifNoneMatch !== null) {
            $request = $request->withHeader('If-None-Match', $ifNoneMatch);
        }

        return $this->controller->show($request);
    }
}
