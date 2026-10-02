<?php

declare(strict_types=1);

namespace Hydra\View;

use RuntimeException;

/**
 * A path given to Assets::url() that names no file the app serves. Thrown in
 * production too: a layout linking a file that is not there is a bug the
 * app's tests should catch, not something to paper over with an unhashed URL.
 */
final class AssetNotFound extends RuntimeException {}
