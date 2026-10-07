<?php

declare(strict_types=1);

namespace Hydra\Image;

use RuntimeException;

/**
 * A source that names no picture the app has: a typo in a view, a path out
 * of the document root, a key not on the public disk. Thrown, like
 * asset()'s AssetNotFound, so the app's tests find it before a reader does.
 */
final class ImageNotFound extends RuntimeException {}
