<?php

declare(strict_types=1);

namespace Hydra\View\Contracts;

use Hydra\View\HtmlView;
use Hydra\View\Variant;

/**
 * One picture at the sizes a page needs. A source is a file under the
 * document root (`/images/garden.jpg`) or a key on the public disk (an
 * upload); a preset is a name the app declared once, with its widths.
 */
interface ImagesInterface
{
    /**
     * An `<img>` with a `srcset` of the preset's sizes, its width and height,
     * and lazy loading unless $eager (the one picture above the fold).
     *
     * @param string $alt what the picture shows; '' marks it decorative
     * @param string|null $sizes the `sizes` attribute: how wide it is drawn
     */
    public function img(string $source, string $preset, string $alt, ?string $sizes = null, bool $eager = false): HtmlView;

    /**
     * The preset's sizes of a source, narrowest first: for a feed or a
     * preview image, which want one URL.
     *
     * @return list<Variant>
     */
    public function variants(string $source, string $preset): array;
}
