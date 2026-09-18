<?php

declare(strict_types=1);

namespace Hydra\Mail\Exceptions;

use RuntimeException;

/**
 * The message was not delivered.
 */
final class TransportException extends RuntimeException {}
