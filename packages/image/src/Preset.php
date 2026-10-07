<?php

declare(strict_types=1);

namespace Hydra\Image;

use InvalidArgumentException;

/**
 * The sizes a picture is made at, declared once and named in ImageOptions:
 *
 *     Preset::widths(480, 960, 1440)          // scaled down, its own shape
 *     Preset::widths(640, 1280)->crop(16, 9)  // cut to 16:9 from the centre
 *
 * Nothing is ever made wider than the source: a source narrower than a width
 * is made at its own width instead, once.
 */
final readonly class Preset
{
    /** Wider than any screen draws a picture, and a guard against a typo. */
    private const MAX_WIDTH = 4096;

    /**
     * @param list<int> $widths narrowest first
     * @param array{int, int}|null $ratio width:height to crop to, or null to keep the source's shape
     */
    private function __construct(
        public array $widths,
        public ?array $ratio = null,
    ) {}

    public static function widths(int ...$widths): self
    {
        if ($widths === []) {
            throw new InvalidArgumentException('A preset needs at least one width.');
        }

        foreach ($widths as $width) {
            if ($width < 1 || $width > self::MAX_WIDTH) {
                throw new InvalidArgumentException(sprintf('A width is 1 to %d pixels; %d was given.', self::MAX_WIDTH, $width));
            }
        }

        $widths = array_values(array_unique($widths));
        sort($widths);

        return new self($widths);
    }

    /** Cut to this shape, from the centre, before scaling. */
    public function crop(int $width, int $height): self
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("A crop ratio is two positive numbers; {$width}:{$height} was given.");
        }

        return new self($this->widths, [$width, $height]);
    }

    /**
     * The part of a source this preset uses, as x, y, width, height: all of
     * it, or the largest centred box of the crop's shape.
     *
     * @return array{int, int, int, int}
     */
    public function region(int $width, int $height): array
    {
        if ($this->ratio === null) {
            return [0, 0, $width, $height];
        }

        [$rw, $rh] = $this->ratio;
        $w = min($width, (int) round($height * $rw / $rh));
        $h = min($height, (int) round($w * $rh / $rw));

        return [intdiv($width - $w, 2), intdiv($height - $h, 2), $w, $h];
    }

    /**
     * The width and height of each picture to make from a source this size,
     * narrowest first: never wider than the part of the source used, and
     * never the same size twice.
     *
     * @return list<array{int, int}>
     */
    public function sizes(int $width, int $height): array
    {
        [, , $w, $h] = $this->region($width, $height);
        $sizes = [];

        foreach ($this->widths as $wanted) {
            $made = min($wanted, $w);
            $tall = $this->ratio === null ? $h * $made / $w : $made * $this->ratio[1] / $this->ratio[0];
            $sizes[$made] = [$made, max(1, (int) round($tall))];
        }

        return array_values($sizes);
    }
}
