<?php

declare(strict_types=1);

namespace Hydra\Console;

/**
 * What a command tells the shell. The integers are the shell's, not ours:
 * 0 is success everywhere, and a non-zero is what stops a `&&` chain and fails
 * a CI step.
 */
enum ExitCode: int
{
    case Success = 0;

    /** The command ran and the answer was no. */
    case Failure = 1;

    /** The command could not run: a missing argument, an unusable value. */
    case Invalid = 2;
}
