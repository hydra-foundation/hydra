<?php

declare(strict_types=1);

namespace Hydra\Mail\Contracts;

use Hydra\Mail\Exceptions\TransportException;
use Hydra\Mail\Message;

/**
 * What application code sends through.
 */
interface MailerInterface
{
    /**
     * @throws TransportException
     * @throws \InvalidArgumentException when the message cannot be sent as built
     */
    public function send(Message $message): void;
}
