<?php

declare(strict_types=1);

namespace Hydra\Seo\Tests\Unit;

use Hydra\Seo\Image;
use Hydra\Seo\SiteMeta;
use InvalidArgumentException;
use Hydra\Seo\Url;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SiteMeta::class)]
#[CoversClass(Url::class)]
#[CoversClass(Image::class)]
final class SiteMetaTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function goodBaseUrls(): iterable
    {
        yield 'https' => ['https://example.com'];
        yield 'http' => ['http://hydra.localhost'];
        yield 'port' => ['http://127.0.0.1:8080'];
        yield 'subdomain' => ['https://blog.example.co.uk'];
    }

    #[DataProvider('goodBaseUrls')]
    public function test_it_accepts_a_scheme_and_host(string $baseUrl): void
    {
        $site = new SiteMeta($baseUrl, 'Site', new Image('/img/share.png', 1200, 630, 'Site'));

        self::assertSame($baseUrl, $site->baseUrl);
    }

    /** @return iterable<string, array{string}> */
    public static function badBaseUrls(): iterable
    {
        yield 'trailing slash' => ['https://example.com/'];
        yield 'a path' => ['https://example.com/blog'];
        yield 'no scheme' => ['example.com'];
        yield 'another scheme' => ['ftp://example.com'];
        yield 'a query' => ['https://example.com?x=1'];
        yield 'empty' => [''];
        yield 'credentials' => ['https://user:pass@example.com'];
    }

    #[DataProvider('badBaseUrls')]
    public function test_it_refuses_a_base_url_that_is_not_just_scheme_and_host(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SiteMeta baseUrl must look like https://example.com (no path, no trailing slash); got "' . $baseUrl . '".');

        new SiteMeta($baseUrl, 'Site', new Image('/img/share.png', 1200, 630, 'Site'));
    }

    public function test_a_title_format_without_a_placeholder_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('titleFormat must contain %s');

        new SiteMeta('https://example.com', 'Site', new Image('/img/share.png', 1200, 630, 'Site'), titleFormat: 'Site');
    }

    public function test_an_image_needs_a_positive_size(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An image needs a positive width and height');

        new Image('/img/share.png', 0, 630, 'Site');
    }

    public function test_an_image_url_is_a_path_or_an_https_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An image is a path starting with "/" or an http(s) URL; got "img/share.png".');

        new Image('img/share.png', 1200, 630, 'Site');
    }
}
