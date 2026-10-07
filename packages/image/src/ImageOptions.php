<?php

declare(strict_types=1);

namespace Hydra\Image;

use InvalidArgumentException;

/**
 * The presets an app declares, and how pictures are made: WebP at this
 * quality, from sources no bigger than this many megapixels. A source over
 * the limit is refused before it is decoded, so one huge or hostile file
 * can't take the memory a page needs.
 */
final readonly class ImageOptions
{
    /** A preset's name is part of a path, so it is a plain segment. */
    private const NAME = '/^[a-z0-9][a-z0-9_-]*$/D';

    /** @param array<string, Preset> $presets by name */
    public function __construct(
        public array $presets,
        public int $quality = 82,
        public int $maxMegapixels = 40,
    ) {
        if ($quality < 1 || $quality > 100) {
            throw new InvalidArgumentException("Quality is 1 to 100; {$quality} was given.");
        }

        if ($maxMegapixels < 1) {
            throw new InvalidArgumentException("The megapixel limit must be at least 1; {$maxMegapixels} was given.");
        }

        foreach (array_keys($presets) as $name) {
            if (preg_match(self::NAME, (string) $name) !== 1) {
                throw new InvalidArgumentException("\"{$name}\" can't name a preset: lowercase letters, digits, - and _.");
            }
        }
    }

    public function preset(string $name): Preset
    {
        return $this->presets[$name] ?? throw new InvalidArgumentException(sprintf(
            'There is no image preset "%s"; the presets are %s.',
            $name,
            $this->presets === [] ? 'none' : implode(', ', array_keys($this->presets)),
        ));
    }
}
