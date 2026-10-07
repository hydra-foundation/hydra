<?php

declare(strict_types=1);

namespace Hydra\Image;

use Closure;
use GdImage;

/**
 * A source file into WebP copies, with GD. The header is read and the size
 * checked before anything is decoded; the picture is turned the way the
 * camera's EXIF says, cut to the preset's region, scaled, and written with
 * none of the original's metadata (GD writes none), each copy to a temporary
 * name first so a reader never meets half a file.
 */
final class GdResizer
{
    /** What GD is asked to read, by the type getimagesize() reports. */
    private const DECODERS = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_GIF => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];

    public function __construct(private readonly ImageOptions $options) {}

    /**
     * The width and height a page lays the picture out at: its own, or
     * swapped when the camera says it was held on its side.
     *
     * @return array{int, int}
     *
     * @throws ImageRefused
     */
    public function inspect(string $path): array
    {
        [$width, $height] = $this->header($path);

        return $this->orientation($path) >= 5 ? [$height, $width] : [$width, $height];
    }

    /**
     * Each of the preset's sizes for this source, written where $target says.
     *
     * @param Closure(int, int): string $target the path for a width and height
     * @return array<int, array{int, int}> the sizes made, by width
     *
     * @throws ImageRefused
     */
    public function make(string $path, Preset $preset, Closure $target): array
    {
        [, , $type] = $this->header($path);
        $image = $this->orient($this->decode($path, $type), $this->orientation($path));
        [$x, $y, $w, $h] = $preset->region(imagesx($image), imagesy($image));
        $made = [];

        foreach ($preset->sizes(imagesx($image), imagesy($image)) as [$width, $height]) {
            $copy = imagecreatetruecolor($width, $height);
            imagealphablending($copy, false);
            imagesavealpha($copy, true);
            imagefill($copy, 0, 0, (int) imagecolorallocatealpha($copy, 0, 0, 0, 127));
            imagecopyresampled($copy, $image, 0, 0, $x, $y, $width, $height, $w, $h);
            $this->write($copy, $target($width, $height));
            $made[$width] = [$width, $height];
        }

        return $made;
    }

    /** @return array{int, int, int} width, height, type */
    private function header(string $path): array
    {
        $size = @getimagesize($path);

        if ($size === false) {
            throw new ImageRefused(basename($path) . ' is not a picture GD can read.');
        }

        [$width, $height, $type] = $size;

        if (!isset(self::DECODERS[$type])) {
            throw new ImageRefused(basename($path) . ' is not a JPEG, PNG, GIF or WebP.');
        }

        $megapixels = $width * $height / 1_000_000;

        if ($megapixels > $this->options->maxMegapixels) {
            throw new ImageRefused(sprintf(
                '%s is %s megapixels, over the limit of %d.',
                basename($path),
                rtrim(rtrim(number_format($megapixels, 1, '.', ''), '0'), '.'),
                $this->options->maxMegapixels,
            ));
        }

        return [$width, $height, $type];
    }

    private function decode(string $path, int $type): GdImage
    {
        $image = @(self::DECODERS[$type])($path);

        if (!$image instanceof GdImage) {
            throw new ImageRefused(basename($path) . ' would not decode.');
        }

        // A GIF or palette PNG decodes to a palette; resampling wants colour.
        imagepalettetotruecolor($image);

        return $image;
    }

    /** The EXIF orientation, 1 to 8; 1 (as stored) for anything without one. */
    private function orientation(string $path): int
    {
        $exif = @exif_read_data($path, 'IFD0');
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /** Turned so the picture is as the camera saw it (imagerotate() turns anticlockwise). */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        $turn = static fn (GdImage $image, int $degrees): GdImage => imagerotate($image, $degrees, 0) ?: $image;

        match ($orientation) {
            2 => imageflip($image, IMG_FLIP_HORIZONTAL),
            4 => imageflip($image, IMG_FLIP_VERTICAL),
            default => null,
        };

        return match ($orientation) {
            3 => $turn($image, 180),
            5 => $this->flipped($turn($image, -90), IMG_FLIP_HORIZONTAL),
            6 => $turn($image, -90),
            7 => $this->flipped($turn($image, -90), IMG_FLIP_VERTICAL),
            8 => $turn($image, 90),
            default => $image,
        };
    }

    private function flipped(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function write(GdImage $image, string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0o775, true);
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (!@imagewebp($image, $temporary, $this->options->quality)) {
            @unlink($temporary);

            throw new ImageRefused('A copy could not be written to ' . $directory . '.');
        }

        rename($temporary, $path);
    }
}
