<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use InvalidArgumentException;

/** SSE_MAX_CONNECTIONS is more than the hub's poller can watch on this PHP. */
final class HubCapacityException extends InvalidArgumentException {}
