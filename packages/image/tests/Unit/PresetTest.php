<?php

declare(strict_types=1);

namespace Hydra\Image\Tests\Unit;

use Hydra\Image\ImageOptions;
use Hydra\Image\Preset;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A preset is a few widths and maybe a crop, and from a source's size it
 * says exactly which sizes to make: never wider than the source, never two
 * the same, a crop taken from the centre.
 */
#[CoversClass(Preset::class)]
#[CoversClass(ImageOptions::class)]
final class PresetTest extends TestCase
{
    public function test_widths_are_kept_narrowest_first_once_each(): void
    {
        $this->assertSame([480, 960, 1440], Preset::widths(1440, 480, 960, 480)->widths);
        $this->assertSame([1, 4096], Preset::widths(4096, 1)->widths, 'the bounds themselves');
    }

    /** @return iterable<string, array{list<int>}> */
    public static function badWidths(): iterable
    {
        yield 'none' => [[]];
        yield 'zero' => [[0]];
        yield 'negative' => [[480, -1]];
        yield 'absurd' => [[10_000]];
    }

    /** @param list<int> $widths */
    #[DataProvider('badWidths')]
    public function test_a_width_must_be_one_a_screen_could_want(array $widths): void
    {
        $this->expectException(InvalidArgumentException::class);

        Preset::widths(...$widths);
    }

    public function test_a_crop_keeps_the_widths_and_names_its_shape(): void
    {
        $preset = Preset::widths(640, 1280)->crop(16, 9);

        $this->assertSame([640, 1280], $preset->widths);
        $this->assertSame([16, 9], $preset->ratio);
        $this->assertNull(Preset::widths(640)->ratio);
    }

    public function test_a_crop_needs_a_real_ratio(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Preset::widths(100)->crop(16, 0);
    }

    /** @return iterable<string, array{Preset, int, int, list<array{int, int}>}> */
    public static function sizes(): iterable
    {
        yield 'a landscape photo, scaled down' => [Preset::widths(480, 960, 1440), 4000, 3000, [[480, 360], [960, 720], [1440, 1080]]];
        yield 'a portrait one' => [Preset::widths(480, 960), 3000, 4000, [[480, 640], [960, 1280]]];
        yield 'narrower than the widths: its own width, once' => [Preset::widths(480, 960, 1440), 800, 600, [[480, 360], [800, 600]]];
        yield 'narrower than every width' => [Preset::widths(480, 960), 300, 200, [[300, 200]]];
        yield 'heights rounded, never zero' => [Preset::widths(100), 4000, 1, [[100, 1]]];
        yield 'cropped 16:9 from a 4:3' => [Preset::widths(640, 1280)->crop(16, 9), 4000, 3000, [[640, 360], [1280, 720]]];
        yield 'cropped square from a portrait' => [Preset::widths(160)->crop(1, 1), 3000, 4000, [[160, 160]]];
        yield 'a crop no wider than the source allows' => [Preset::widths(640, 1280)->crop(16, 9), 1000, 1000, [[640, 360], [1000, 563]]];
        yield 'a third rounds down' => [Preset::widths(100), 3000, 1000, [[100, 33]]];
    }

    /** @param list<array{int, int}> $expected */
    #[DataProvider('sizes')]
    public function test_the_sizes_a_source_gets(Preset $preset, int $width, int $height, array $expected): void
    {
        $this->assertSame($expected, $preset->sizes($width, $height));
    }

    /** @return iterable<string, array{Preset, int, int, array{int, int, int, int}}> */
    public static function regions(): iterable
    {
        yield 'no crop: the whole picture' => [Preset::widths(100), 400, 300, [0, 0, 400, 300]];
        yield 'wide from a 4:3, the middle band' => [Preset::widths(100)->crop(16, 9), 4000, 3000, [0, 375, 4000, 2250]];
        yield 'square from a landscape, the middle' => [Preset::widths(100)->crop(1, 1), 400, 300, [50, 0, 300, 300]];
        yield 'square from a portrait, the middle' => [Preset::widths(100)->crop(1, 1), 300, 400, [0, 50, 300, 300]];
        yield 'tall from a landscape, the middle' => [Preset::widths(100)->crop(9, 16), 4000, 3000, [1156, 0, 1688, 3000]];
        yield 'a width that rounds down' => [Preset::widths(100)->crop(9, 16), 1000, 1001, [218, 0, 563, 1001]];
        yield 'a height that rounds down' => [Preset::widths(100)->crop(16, 9), 1001, 1001, [0, 219, 1001, 563]];
        yield 'a height that rounds up' => [Preset::widths(100)->crop(16, 9), 1009, 1009, [0, 220, 1009, 568]];
    }

    /** @param array{int, int, int, int} $region */
    #[DataProvider('regions')]
    public function test_a_crop_is_taken_from_the_centre(Preset $preset, int $width, int $height, array $region): void
    {
        $this->assertSame($region, $preset->region($width, $height));
    }

    public function test_options_name_their_presets(): void
    {
        $content = Preset::widths(480);
        $options = new ImageOptions(['content' => $content]);

        $this->assertSame($content, $options->preset('content'));
        $this->assertSame(82, $options->quality);
        $this->assertSame(40, $options->maxMegapixels);
        $this->assertSame(['/images'], $options->directories);
    }

    public function test_options_at_their_bounds_are_fine(): void
    {
        $this->assertSame(1, (new ImageOptions([], quality: 1))->quality);
        $this->assertSame(100, (new ImageOptions([], quality: 100))->quality);
        $this->assertSame(1, (new ImageOptions([], maxMegapixels: 1))->maxMegapixels);
        $this->assertSame(['/images', '', '/a/b'], (new ImageOptions([], directories: ['/images/', '/', '/a/b']))->directories);
    }

    public function test_with_no_presets_a_name_is_a_typo_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the presets are none');

        (new ImageOptions([]))->preset('content');
    }

    public function test_an_unknown_preset_is_a_typo_and_says_which(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no image preset "hero"; the presets are content, thumb.');

        (new ImageOptions(['content' => Preset::widths(480), 'thumb' => Preset::widths(160)]))->preset('hero');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badOptions(): iterable
    {
        yield 'a quality past 100' => [['quality' => 101]];
        yield 'a quality of nothing' => [['quality' => 0]];
        yield 'no megapixels' => [['maxMegapixels' => 0]];
        yield 'a preset name that is not a path segment' => [['presets' => ['a/b' => Preset::widths(1)]]];
        yield 'a directory not from the root' => [['directories' => ['images']]];
        yield 'a directory climbing out' => [['directories' => ['/images/../..']]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badOptions')]
    public function test_options_that_make_no_sense_are_refused(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ImageOptions(...['presets' => [], ...$options]);
    }
}
