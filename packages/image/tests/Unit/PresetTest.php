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
    }

    public function test_an_unknown_preset_is_a_typo_and_says_which(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"hero"');

        (new ImageOptions(['content' => Preset::widths(480)]))->preset('hero');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badOptions(): iterable
    {
        yield 'a quality past 100' => [['quality' => 101]];
        yield 'a quality of nothing' => [['quality' => 0]];
        yield 'no megapixels' => [['maxMegapixels' => 0]];
        yield 'a preset name that is not a path segment' => [['presets' => ['a/b' => Preset::widths(1)]]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badOptions')]
    public function test_options_that_make_no_sense_are_refused(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ImageOptions(...['presets' => [], ...$options]);
    }
}
