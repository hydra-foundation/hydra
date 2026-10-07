<?php

declare(strict_types=1);

namespace Hydra\Image\Tests\Unit;

use Closure;
use GdImage;
use Hydra\Image\GdResizer;
use Hydra\Image\ImageOptions;
use Hydra\Image\ImageRefused;
use Hydra\Image\Preset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GD between a source file and its WebP copies: the header read and the
 * size refused before anything is decoded, the picture turned the way the
 * camera meant, cut and scaled, and written with nothing of the original's
 * metadata. Every fixture is drawn here, the EXIF one with its orientation
 * spliced in by hand, so a test says exactly what it feeds the resizer.
 */
#[CoversClass(GdResizer::class)]
#[CoversClass(ImageRefused::class)]
final class GdResizerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-resizer-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        yield 'jpeg' => ['jpg'];
        yield 'png' => ['png'];
        yield 'gif' => ['gif'];
        yield 'webp' => ['webp'];
    }

    #[DataProvider('formats')]
    public function test_each_format_comes_out_as_webp_at_each_size(string $format): void
    {
        $source = $this->draw($format, 400, 300);

        $this->assertSame([400, 300], $this->resizer()->inspect($source));

        $made = $this->resizer()->make($source, Preset::widths(100, 200), fn (int $width): string => $this->out("w{$width}"));

        $this->assertSame([100 => [100, 75], 200 => [200, 150]], $made);
        $this->assertSame([100, 75, IMAGETYPE_WEBP], $this->dimensions($this->out('w100')));
        $this->assertSame([200, 150, IMAGETYPE_WEBP], $this->dimensions($this->out('w200')));
    }

    public function test_a_crop_is_the_middle_of_the_picture(): void
    {
        // Red on the left and right quarters, green in the middle half: a
        // centred square of a 4:2 picture is all green.
        $image = imagecreatetruecolor(400, 200);
        $this->assertInstanceOf(GdImage::class, $image);
        imagefill($image, 0, 0, $this->colour($image, 255, 0, 0));
        imagefilledrectangle($image, 100, 0, 299, 199, $this->colour($image, 0, 255, 0));
        imagepng($image, $source = $this->dir . '/wide.png');

        $made = $this->resizer()->make($source, Preset::widths(50)->crop(1, 1), $this->to('square'));

        $this->assertSame([50 => [50, 50]], $made);
        $this->assertSame('green', $this->colourAt($this->out('square'), 2, 25));
        $this->assertSame('green', $this->colourAt($this->out('square'), 47, 25));
    }

    public function test_transparency_survives(): void
    {
        $image = imagecreatetruecolor(100, 100);
        $this->assertInstanceOf(GdImage::class, $image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $source = $this->dir . '/clear.png');

        $this->resizer()->make($source, Preset::widths(50), $this->to('clear'));

        $out = imagecreatefromwebp($this->out('clear'));
        $this->assertInstanceOf(GdImage::class, $out);
        $this->assertSame(127, (imagecolorat($out, 25, 25) >> 24) & 0x7F);
    }

    public function test_a_gif_keeps_its_palette_colours(): void
    {
        $source = $this->draw('gif', 40, 20);

        $this->resizer()->make($source, Preset::widths(40), $this->to('gif'));

        $this->assertSame('red', $this->colourAt($this->out('gif'), 5, 5));
    }

    /**
     * The stored picture has red top left, green top right, blue bottom
     * left, white bottom right; the camera's tag says how to turn it.
     *
     * @return iterable<string, array{int, bool, string, string}>
     */
    public static function orientations(): iterable
    {
        // orientation, sides swapped, where red ends up, where green does
        yield '1 as stored' => [1, false, 'tl', 'tr'];
        yield '2 mirrored' => [2, false, 'tr', 'tl'];
        yield '3 upside down' => [3, false, 'br', 'bl'];
        yield '4 flipped' => [4, false, 'bl', 'br'];
        yield '5 transposed' => [5, true, 'tl', 'bl'];
        yield '6 a quarter turn right' => [6, true, 'tr', 'br'];
        yield '7 transversed' => [7, true, 'br', 'tr'];
        yield '8 a quarter turn left' => [8, true, 'bl', 'tl'];
    }

    #[DataProvider('orientations')]
    public function test_a_photo_comes_out_the_way_the_camera_meant(int $orientation, bool $swapped, string $red, string $green): void
    {
        $source = $this->quadrants(80, 40, $orientation);

        $this->assertSame($swapped ? [40, 80] : [80, 40], $this->resizer()->inspect($source), 'the size a page lays out');

        $this->resizer()->make($source, Preset::widths(80), $this->to('turned'));
        [$width, $height] = $this->dimensions($this->out('turned'));

        $this->assertSame($swapped ? [40, 80] : [80, 40], [$width, $height]);
        $this->assertSame('red', $this->colourAt($this->out('turned'), ...$this->corner($red, $width, $height)));
        $this->assertSame('green', $this->colourAt($this->out('turned'), ...$this->corner($green, $width, $height)));
    }

    /** @return iterable<string, array{int|null}> */
    public static function noTurn(): iterable
    {
        yield 'EXIF without an orientation' => [null];
        yield 'an orientation that is no orientation' => [9];
        yield 'zero' => [0];
    }

    #[DataProvider('noTurn')]
    public function test_a_photo_without_a_real_orientation_is_as_stored(?int $orientation): void
    {
        $source = $this->quadrants(80, 40, $orientation);

        $this->assertSame([80, 40], $this->resizer()->inspect($source));
        $this->resizer()->make($source, Preset::widths(80), $this->to('as-stored'));

        $this->assertSame('red', $this->colourAt($this->out('as-stored'), 20, 10));
        $this->assertSame('green', $this->colourAt($this->out('as-stored'), 60, 10));
    }

    public function test_nothing_of_the_original_metadata_comes_out(): void
    {
        $source = $this->quadrants(80, 40, 1, comment: 'GPS 51.0447 -114.0719');
        $this->assertStringContainsString('Exif', (string) file_get_contents($source));
        $this->assertStringContainsString('GPS 51.0447', (string) file_get_contents($source));

        $this->resizer()->make($source, Preset::widths(80), $this->to('clean'));
        $bytes = (string) file_get_contents($this->out('clean'));

        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertStringNotContainsString('EXIF', $bytes);
        $this->assertStringNotContainsString('GPS', $bytes);
        $this->assertStringNotContainsString('ICCP', $bytes);
    }

    public function test_a_header_claiming_too_many_pixels_is_refused_before_decoding(): void
    {
        // A 1x1 PNG whose header says 10000x5000: fifty megapixels it would
        // try to allocate. getimagesize() reads the claim; the CRC is not
        // checked, which is the point of a crafted file.
        $image = imagecreatetruecolor(1, 1);
        $this->assertInstanceOf(GdImage::class, $image);
        imagepng($image, $source = $this->dir . '/bomb.png');
        $bytes = (string) file_get_contents($source);
        file_put_contents($source, substr_replace($bytes, pack('NN', 10_000, 5_000), 16, 8));

        $this->expectException(ImageRefused::class);
        $this->expectExceptionMessage('bomb.png is 50 megapixels, over the limit of 40.');

        $this->resizer()->inspect($source);
    }

    public function test_the_limit_itself_is_allowed_and_a_pixel_more_is_not(): void
    {
        $limit = new GdResizer(new ImageOptions([], maxMegapixels: 1));

        $this->assertSame([1000, 1000], $limit->inspect($this->draw('png', 1000, 1000)));

        $this->expectException(ImageRefused::class);
        $this->expectExceptionMessage('is 1.3 megapixels');

        $limit->inspect($this->draw('png', 1250, 1000));
    }

    public function test_the_limit_is_the_options(): void
    {
        $source = $this->draw('png', 2000, 1000);

        $this->assertSame([2000, 1000], (new GdResizer(new ImageOptions([], maxMegapixels: 2)))->inspect($source));

        $this->expectException(ImageRefused::class);
        (new GdResizer(new ImageOptions([], maxMegapixels: 1)))->inspect($source);
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        file_put_contents($source = $this->dir . '/note.jpg', 'not a picture at all');

        $this->expectException(ImageRefused::class);
        $this->expectExceptionMessage('note.jpg is not a picture GD can read.');

        $this->resizer()->inspect($source);
    }

    public function test_a_format_it_does_not_make_is_refused(): void
    {
        file_put_contents($source = $this->dir . '/a.bmp', "BM" . str_repeat("\0", 60));
        $image = imagecreatetruecolor(4, 4);
        $this->assertInstanceOf(GdImage::class, $image);
        imagebmp($image, $source);

        $this->expectException(ImageRefused::class);
        $this->expectExceptionMessage('a.bmp is not a JPEG, PNG, GIF or WebP.');

        $this->resizer()->inspect($source);
    }

    public function test_a_picture_whose_body_is_broken_is_refused_when_decoded(): void
    {
        // A WebP header getimagesize() reads the size from, and nothing of
        // the picture behind it: it passes the checks and fails in GD.
        $bytes = (string) file_get_contents($this->draw('webp', 40, 20));
        file_put_contents($source = $this->dir . '/torn.webp', substr($bytes, 0, 30));
        $this->assertSame([40, 20], $this->resizer()->inspect($source));

        $this->expectException(ImageRefused::class);
        $this->expectExceptionMessage('torn.webp would not decode.');

        $this->resizer()->make($source, Preset::widths(40), $this->to('torn'));
    }

    public function test_a_copy_that_cannot_be_written_says_where(): void
    {
        $source = $this->draw('png', 40, 20);
        file_put_contents($this->dir . '/blocked', 'a file where a directory should be');

        try {
            $this->resizer()->make($source, Preset::widths(40), fn (): string => $this->dir . '/blocked/copy.webp');
            $this->fail('A copy with nowhere to go must not pass silently.');
        } catch (ImageRefused $e) {
            $this->assertSame('A copy could not be written to ' . $this->dir . '/blocked.', $e->getMessage());
        }

        $this->assertSame([$this->dir . '/blocked', $source], $this->files());
    }

    public function test_no_temporary_file_is_left_behind(): void
    {
        $source = $this->draw('png', 40, 20);

        $this->resizer()->make($source, Preset::widths(40), $this->to('whole'));

        $this->assertSame([$source, $this->out('whole')], $this->files());
    }

    public function test_it_makes_the_directory_a_copy_goes_in(): void
    {
        $source = $this->draw('png', 40, 20);
        $deep = $this->dir . '/variants/content/x.webp';

        $this->resizer()->make($source, Preset::widths(40), static fn (): string => $deep);

        $this->assertFileExists($deep);
        unlink($deep);
        rmdir($this->dir . '/variants/content');
        rmdir($this->dir . '/variants');
    }

    private function resizer(): GdResizer
    {
        return new GdResizer(new ImageOptions([]));
    }

    /** @return Closure(int, int): string every size to one file: tests that make one size */
    private function to(string $name): Closure
    {
        return fn (): string => $this->out($name);
    }

    private function out(string $name): string
    {
        return "{$this->dir}/{$name}.webp";
    }

    /** @return list<string> everything in the directory, sorted */
    private function files(): array
    {
        $files = glob($this->dir . '/*') ?: [];
        sort($files);

        return $files;
    }

    /** @return array{int, int, int} */
    private function dimensions(string $path): array
    {
        $size = getimagesize($path);
        $this->assertNotFalse($size);

        return [$size[0], $size[1], $size[2]];
    }

    private function draw(string $format, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(GdImage::class, $image);
        imagefill($image, 0, 0, $this->colour($image, 255, 0, 0));
        $path = "{$this->dir}/source-{$width}x{$height}.{$format}";

        match ($format) {
            'jpg' => imagejpeg($image, $path, 95),
            'png' => imagepng($image, $path),
            'gif' => imagegif($image, $path),
            default => imagewebp($image, $path, 95),
        };

        return $path;
    }

    /** A JPEG of four coloured quadrants, with an EXIF orientation and a comment spliced in. */
    private function quadrants(int $width, int $height, ?int $orientation, string $comment = ''): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(GdImage::class, $image);
        [$w, $h] = [intdiv($width, 2), intdiv($height, 2)];
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, $this->colour($image, 255, 0, 0));
        imagefilledrectangle($image, $w, 0, $width - 1, $h - 1, $this->colour($image, 0, 255, 0));
        imagefilledrectangle($image, 0, $h, $w - 1, $height - 1, $this->colour($image, 0, 0, 255));
        imagefilledrectangle($image, $w, $h, $width - 1, $height - 1, $this->colour($image, 255, 255, 255));

        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = (string) ob_get_clean();

        // APP1 "Exif": a big-endian TIFF header, one IFD with one entry,
        // Orientation (0x0112), SHORT, count 1, the value left-aligned.
        // With no orientation, the one entry is Make (0x010F, ASCII "Abc").
        $entry = $orientation === null
            ? pack('nnN', 0x010F, 2, 4) . "Abc\0"
            : pack('nnNnn', 0x0112, 3, 1, $orientation, 0);
        $tiff = 'MM' . pack('nN', 42, 8) . pack('n', 1) . $entry . pack('N', 0);
        $app1 = "\xFF\xE1" . pack('n', strlen('Exif' . "\0\0" . $tiff) + 2) . "Exif\0\0" . $tiff;
        $com = $comment === '' ? '' : "\xFF\xFE" . pack('n', strlen($comment) + 2) . $comment;
        $path = "{$this->dir}/photo-" . ($orientation ?? 'none') . '.jpg';
        file_put_contents($path, "\xFF\xD8" . $app1 . $com . substr($jpeg, 2));

        return $path;
    }

    /** @return array{int, int} a point well inside the named quadrant */
    private function corner(string $corner, int $width, int $height): array
    {
        return [
            str_ends_with($corner, 'l') ? intdiv($width, 4) : intdiv($width * 3, 4),
            str_starts_with($corner, 't') ? intdiv($height, 4) : intdiv($height * 3, 4),
        ];
    }

    private function colourAt(string $path, int $x, int $y): string
    {
        $image = imagecreatefromwebp($path);
        $this->assertInstanceOf(GdImage::class, $image);
        $rgb = imagecolorat($image, $x, $y);
        [$r, $g, $b] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];

        return match (true) {
            $r > 200 && $g > 200 && $b > 200 => 'white',
            $r > 200 && $g < 60 && $b < 60 => 'red',
            $g > 200 && $r < 60 && $b < 60 => 'green',
            $b > 200 && $r < 60 && $g < 60 => 'blue',
            default => sprintf('rgb(%d, %d, %d)', $r, $g, $b),
        };
    }

    private function colour(GdImage $image, int $r, int $g, int $b): int
    {
        $colour = imagecolorallocate($image, $r, $g, $b);
        $this->assertNotFalse($colour);

        return $colour;
    }
}
