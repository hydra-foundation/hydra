<?php

declare(strict_types=1);

namespace Hydra\Image\Tests\Unit;

use GdImage;
use Hydra\Image\GdImages;
use Hydra\Image\GdResizer;
use Hydra\Image\ImageNotFound;
use Hydra\Image\ImageOptions;
use Hydra\Image\ImageRefused;
use Hydra\Image\Preset;
use Hydra\View\Variant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A view's picture: where the source is, what its copies are called, that
 * they are made once and kept, and the <img> that offers them. A source that
 * isn't there is a mistake that throws; one that won't decode is the
 * original alone, logged, so one bad picture never takes a page down.
 */
#[CoversClass(GdImages::class)]
#[CoversClass(ImageNotFound::class)]
final class GdImagesTest extends TestCase
{
    private string $root;

    private string $disk;

    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/hydra-images-' . bin2hex(random_bytes(6));
        $this->root = $base . '/public';
        $this->disk = $base . '/storage';
        mkdir($this->root . '/images/posts', 0o777, true);
        mkdir($this->disk . '/posts', 0o777, true);
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = "{$level}: " . strtr((string) $message, ['{source}' => (string) ($context['source'] ?? ''), '{reason}' => (string) ($context['reason'] ?? '')]);
            }
        };
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->root)));
    }

    public function test_a_picture_in_the_document_root_gets_its_copies(): void
    {
        $this->draw($this->root . '/images/posts/garden.jpg', 2000, 1500);

        $variants = $this->images()->variants('/images/posts/garden.jpg', 'content');

        $this->assertSame([[480, 360], [960, 720], [1440, 1080]], array_map(static fn (Variant $v): array => [$v->width, $v->height], $variants));

        foreach ($variants as $variant) {
            $this->assertMatchesRegularExpression('#^/storage/variants/content/[0-9a-f]{16}-\d+\.webp$#', $variant->url);
            $file = $this->disk . substr($variant->url, strlen('/storage'));
            $this->assertFileExists($file);
            $this->assertSame([$variant->width, $variant->height], array_slice((array) getimagesize($file), 0, 2));
        }
    }

    public function test_an_upload_on_the_public_disk_too(): void
    {
        $this->draw($this->disk . '/posts/9c1e.png', 800, 600);

        $variants = $this->images()->variants('posts/9c1e.png', 'content');

        $this->assertSame([480, 800], array_map(static fn (Variant $v): int => $v->width, $variants));
    }

    public function test_copies_are_made_once(): void
    {
        $this->draw($this->root . '/images/a.jpg', 1000, 500);
        $first = $this->images()->variants('/images/a.jpg', 'content');
        $made = filemtime($this->disk . substr($first[0]->url, 8));
        touch($this->disk . substr($first[0]->url, 8), 1_000_000_000);

        $again = $this->images()->variants('/images/a.jpg', 'content');

        $this->assertEquals($first, $again);
        $this->assertNotFalse($made);
        $this->assertSame(1_000_000_000, filemtime($this->disk . substr($first[0]->url, 8)), 'left alone, not remade');
    }

    public function test_a_missing_copy_is_made_again(): void
    {
        $this->draw($this->root . '/images/a.jpg', 1000, 500);
        $first = $this->images()->variants('/images/a.jpg', 'content');
        unlink($this->disk . substr($first[1]->url, 8));

        $this->images()->variants('/images/a.jpg', 'content');

        $this->assertFileExists($this->disk . substr($first[1]->url, 8));
    }

    public function test_an_edited_picture_or_preset_gets_new_names(): void
    {
        $path = $this->root . '/images/a.jpg';
        $this->draw($path, 1000, 500);
        $before = $this->images()->variants('/images/a.jpg', 'content')[0]->url;

        $this->draw($path, 1000, 400);
        touch($path, time() + 10);
        clearstatcache();
        $edited = $this->images()->variants('/images/a.jpg', 'content')[0]->url;

        $wider = new GdImages(
            new ImageOptions(['content' => Preset::widths(480, 1024)]),
            $this->root,
            $this->disk,
        );

        $this->assertNotSame($before, $edited);
        $this->assertNotSame($edited, $wider->variants('/images/a.jpg', 'content')[0]->url);
    }

    public function test_the_same_picture_in_two_places_is_two_sets(): void
    {
        $this->draw($this->root . '/images/a.jpg', 1000, 500);
        copy($this->root . '/images/a.jpg', $this->disk . '/posts/a.jpg');

        $this->assertNotSame(
            $this->images()->variants('/images/a.jpg', 'thumb')[0]->url,
            $this->images()->variants('posts/a.jpg', 'thumb')[0]->url,
        );
    }

    public function test_the_img_offers_every_size(): void
    {
        $this->draw($this->root . '/images/garden.jpg', 2000, 1500);
        $variants = $this->images()->variants('/images/garden.jpg', 'content');

        $html = (string) $this->images()->img('/images/garden.jpg', 'content', alt: 'The "garden" & more', sizes: '(min-width: 48rem) 48rem, 100vw');

        $this->assertSame(sprintf(
            '<img src="%s" srcset="%s 480w, %s 960w, %s 1440w" sizes="(min-width: 48rem) 48rem, 100vw" width="960" height="720" alt="The &quot;garden&quot; &amp; more" loading="lazy" decoding="async">',
            $variants[1]->url,
            $variants[0]->url,
            $variants[1]->url,
            $variants[2]->url,
        ), $html);
    }

    public function test_the_picture_above_the_fold_loads_first(): void
    {
        $this->draw($this->root . '/images/hero.jpg', 2000, 1000);

        $html = (string) $this->images()->img('/images/hero.jpg', 'cover', alt: '', eager: true);

        $this->assertStringContainsString('fetchpriority="high"', $html);
        $this->assertStringNotContainsString('loading=', $html);
        $this->assertStringContainsString('alt=""', $html, 'decorative, said so');
        $this->assertStringNotContainsString('sizes=', $html, 'no sizes unless given');
    }

    public function test_one_size_is_a_plain_img(): void
    {
        $this->draw($this->root . '/images/icon.png', 400, 400);

        $html = (string) $this->images()->img('/images/icon.png', 'thumb', alt: 'Icon', sizes: '160px');

        $this->assertStringNotContainsString('srcset', $html);
        $this->assertStringNotContainsString('sizes', $html);
        $this->assertStringContainsString('width="160" height="160"', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function badSources(): iterable
    {
        yield 'not there' => ['/images/nope.jpg'];
        yield 'climbing out' => ['/images/../../etc/passwd'];
        yield 'another host' => ['//evil.example/a.jpg'];
        yield 'a query' => ['/images/a.jpg?v=1'];
        yield 'a null byte' => ["/images/a.jpg\0"];
        yield 'a directory' => ['/images'];
        yield 'a key not on the disk' => ['posts/nope.png'];
        yield 'a key climbing out' => ['../public/images/a.jpg'];
        yield 'a copy, not a source' => ['variants/content/abc-480.webp'];
    }

    #[DataProvider('badSources')]
    public function test_a_source_that_is_not_there_is_a_mistake(string $source): void
    {
        $this->draw($this->root . '/images/a.jpg', 100, 100);

        $this->expectException(ImageNotFound::class);

        $this->images()->img($source, 'content', alt: '');
    }

    public function test_a_link_out_of_the_document_root_is_refused(): void
    {
        $this->draw($this->disk . '/posts/a.png', 100, 100);
        symlink($this->disk . '/posts', $this->root . '/images/linked');

        $this->expectException(ImageNotFound::class);

        $this->images()->variants('/images/linked/a.png', 'content');
    }

    public function test_an_unknown_preset_is_a_mistake(): void
    {
        $this->draw($this->root . '/images/a.jpg', 100, 100);

        $this->expectException(\InvalidArgumentException::class);

        $this->images()->img('/images/a.jpg', 'hero', alt: '');
    }

    public function test_a_picture_that_wont_decode_is_the_original_alone_and_logged(): void
    {
        file_put_contents($this->root . '/images/broken.jpg', 'not a picture');

        $html = (string) $this->images()->img('/images/broken.jpg', 'content', alt: 'Broken', sizes: '100vw');

        $this->assertSame('<img src="/images/broken.jpg" alt="Broken" loading="lazy" decoding="async">', $html);
        $this->assertCount(1, $this->lines());
        $this->assertStringStartsWith('warning: /images/broken.jpg', $this->lines()[0]);
    }

    public function test_an_upload_that_wont_decode_links_its_own_url(): void
    {
        file_put_contents($this->disk . '/posts/x.png', 'nope');

        $this->assertStringStartsWith('<img src="/storage/posts/x.png"', (string) $this->images()->img('posts/x.png', 'content', alt: ''));
    }

    public function test_asked_directly_a_broken_picture_says_why(): void
    {
        file_put_contents($this->root . '/images/broken.jpg', 'not a picture');

        $this->expectException(ImageRefused::class);

        $this->images()->variants('/images/broken.jpg', 'content');
    }

    public function test_an_svg_is_drawn_at_any_size_so_it_passes_through(): void
    {
        file_put_contents($this->root . '/images/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');

        $this->assertSame('<img src="/images/logo.svg" alt="Logo" loading="lazy" decoding="async">', (string) $this->images()->img('/images/logo.svg', 'content', alt: 'Logo'));
        $this->assertEquals([new Variant('/images/logo.svg', 0, 0)], $this->images()->variants('/images/logo.svg', 'content'));
        $this->assertSame([], $this->lines());
    }

    public function test_the_disk_url_is_the_configured_one(): void
    {
        $this->draw($this->root . '/images/a.jpg', 600, 300);
        $images = new GdImages($this->options(), $this->root, $this->disk, 'https://cdn.example/files/');

        $this->assertStringStartsWith('https://cdn.example/files/variants/thumb/', $images->variants('/images/a.jpg', 'thumb')[0]->url);
    }

    private function images(): GdImages
    {
        return new GdImages($this->options(), $this->root, $this->disk, '/storage', $this->logger, new GdResizer($this->options()));
    }

    private function options(): ImageOptions
    {
        return new ImageOptions([
            'content' => Preset::widths(480, 960, 1440),
            'cover' => Preset::widths(640, 1280)->crop(16, 9),
            'thumb' => Preset::widths(160)->crop(1, 1),
        ]);
    }

    /** @return list<string> */
    private function lines(): array
    {
        /** @var list<string> */
        return $this->logger->lines ?? [];
    }

    private function draw(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(GdImage::class, $image);
        str_ends_with($path, '.png') ? imagepng($image, $path) : imagejpeg($image, $path);
    }
}
