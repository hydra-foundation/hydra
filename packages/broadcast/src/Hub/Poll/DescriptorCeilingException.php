<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub\Poll;

use RuntimeException;

/** A socket handed to stream_select is numbered past FD_SETSIZE, so select() refused the whole set. */
final class DescriptorCeilingException extends RuntimeException {}
