<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\FileController;
use Hydra\Admin\Tests\Support\TemporaryDisks;
use Hydra\Admin\Uploads;
use Hydra\Filesystem\Disks;
use Hydra\Http\Exceptions\NotFoundException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The one way a private file leaves the disk. Which files it will hand out,
 * and how it tells a browser to treat them, are the whole of its job.
 */
#[CoversClass(FileController::class)]
final class FileControllerTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private TemporaryDisks $disks;
    private FileController $controller;

    protected function setUp(): void
    {
        $this->disks = new TemporaryDisks;
        $this->controller = new FileController(new Psr17Factory, new Uploads($this->disks->disks));
    }

    protected function tearDown(): void
    {
        $this->disks->remove();
    }

    public function test_a_private_image_is_served_inline_with_its_detected_type(): void
    {
        $response = $this->get($this->private('avatars', base64_decode(self::PNG)));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(base64_decode(self::PNG), (string) $response->getBody());
        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertSame('inline', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame((string) strlen(base64_decode(self::PNG)), $response->getHeaderLine('Content-Length'));
    }

    public function test_the_browser_is_told_not_to_second_guess_the_type(): void
    {
        $response = $this->get($this->private('avatars', base64_decode(self::PNG)));

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function test_a_file_is_cached_privately_and_for_good_since_a_key_never_changes_content(): void
    {
        $cache = $this->get($this->private('avatars', base64_decode(self::PNG)))->getHeaderLine('Cache-Control');

        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('immutable', $cache);
    }

    public function test_anything_but_a_plain_image_is_a_download(): void
    {
        $response = $this->get($this->private('files', "just a note\n"));

        $this->assertSame('attachment', $response->getHeaderLine('Content-Disposition'));
    }

    public function test_an_svg_is_never_rendered_even_though_it_is_an_image(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $response = $this->get($this->private('files', $svg));

        $this->assertSame('attachment', $response->getHeaderLine('Content-Disposition'));
    }

    public function test_a_public_file_is_not_served_here(): void
    {
        $key = $this->disks->disks->qualify(
            Disks::PUBLIC,
            $this->disks->disks->public()->put('blog', Stream::create(base64_decode(self::PNG))),
        );

        $this->expectException(NotFoundException::class);

        $this->get($key);
    }

    #[DataProvider('unservable')]
    public function test_a_key_that_names_no_private_file_is_not_found(string $key): void
    {
        $this->expectException(NotFoundException::class);

        $this->get($key);
    }

    /** @return iterable<string, array{string}> */
    public static function unservable(): iterable
    {
        yield 'missing' => ['private:avatars/' . str_repeat('0', 32) . '.png'];
        yield 'traversal' => ['private:../../.env'];
        yield 'absolute' => ['private:/etc/passwd'];
        yield 'no disk' => ['avatars/x.png'];
        yield 'unknown disk' => ['s3:avatars/x.png'];
        yield 'empty' => [''];
    }

    public function test_without_disks_there_is_nothing_to_serve(): void
    {
        $this->expectException(NotFoundException::class);

        (new FileController(new Psr17Factory))->show(
            (new Psr17Factory)->createServerRequest('GET', '/admin/files')->withQueryParams(['key' => 'private:a/b.png']),
        );
    }

    private function private(string $directory, string $bytes): string
    {
        return $this->disks->disks->qualify(
            Disks::PRIVATE,
            $this->disks->disks->private()->put($directory, Stream::create($bytes)),
        );
    }

    private function get(string $key): ResponseInterface
    {
        return $this->controller->show(
            (new Psr17Factory)->createServerRequest('GET', '/admin/files')->withQueryParams(['key' => $key]),
        );
    }
}
