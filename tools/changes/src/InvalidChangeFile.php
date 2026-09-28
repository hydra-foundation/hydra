<?php

declare(strict_types=1);

namespace Hydra\Tools\Changes;

/**
 * A fragment or release file that does not parse. The message leads with
 * `file:line:` so an editor or terminal can jump straight to the problem,
 * and `bin/changes.php check` can print it as-is.
 *
 * `$path` and `$lineNumber` rather than `$file` and `$line`, which Exception
 * already uses for where it was thrown.
 */
final class InvalidChangeFile extends \RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly ?int $lineNumber,
        string $problem,
    ) {
        parent::__construct(($lineNumber === null ? $path : "$path:$lineNumber") . ": $problem");
    }
}
