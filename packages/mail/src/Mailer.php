<?php

declare(strict_types=1);

namespace Hydra\Mail;

use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Mail\Contracts\TransportInterface;
use InvalidArgumentException;

/**
 * Completes a message with the application's sender and checks it is sendable
 * before any transport sees it, so every transport fails the same way.
 */
final readonly class Mailer implements MailerInterface
{
    public function __construct(
        private TransportInterface $transport,
        private ?Address $from = null,
    ) {}

    public function send(Message $message): void
    {
        if ($message->getFrom() === null) {
            if ($this->from === null) {
                throw new InvalidArgumentException('The message has no sender, and MAIL_FROM_ADDRESS is not set.');
            }

            $message = $message->from($this->from->email, $this->from->name);
        }

        if ($message->recipients() === []) {
            throw new InvalidArgumentException('The message has no recipients.');
        }

        if ($message->getText() === null && $message->getHtml() === null) {
            throw new InvalidArgumentException('The message has no body.');
        }

        $this->transport->send($message);
    }
}
