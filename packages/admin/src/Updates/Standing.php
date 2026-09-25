<?php

declare(strict_types=1);

namespace Hydra\Admin\Updates;

enum Standing
{
    case Off;
    case Unreleased;
    case Unknown;
    case Current;
    case Patch;
    case Series;
}
