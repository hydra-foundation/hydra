<?php

declare(strict_types=1);

namespace Hydra\Image;

use RuntimeException;

/**
 * A source that won't be made into variants: not a picture, a format GD
 * isn't asked to read, more pixels than the limit, or a body that won't
 * decode. The page prints the original instead and logs this.
 */
final class ImageRefused extends RuntimeException {}
