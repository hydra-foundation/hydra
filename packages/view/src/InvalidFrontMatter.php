<?php

declare(strict_types=1);

namespace Hydra\View;

use RuntimeException;

/**
 * A content file whose front matter can't be read: malformed YAML, or YAML
 * that isn't a mapping of names to values. Thrown rather than read as
 * empty, because a post whose title silently vanished is a bug nobody sees.
 */
final class InvalidFrontMatter extends RuntimeException {}
