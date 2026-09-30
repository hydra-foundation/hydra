<?php

declare(strict_types=1);

namespace Hydra\Broadcast;

/**
 * What a listen token is, as the hub needs to know it. Expired and Invalid are
 * answered differently: an expired token is an honest page that should fetch a
 * fresh one, a forged one is refused.
 */
enum TokenState
{
    case Valid;
    case Expired;
    case Invalid;
}
