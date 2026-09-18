<?php

declare(strict_types=1);

namespace Hydra\Mail\Contracts;

use Hydra\Mail\Exceptions\TransportException;
use Hydra\Mail\Message;

/**
 * Hands a complete message to whatever delivers it.
 */
interface TransportInterface
{
    /**
     * Returns once the message has been accepted. Nothing is accepted for any
     * recipient when this throws.
     *
     * @throws TransportException
     */
    public function send(Message $message): void;
}
