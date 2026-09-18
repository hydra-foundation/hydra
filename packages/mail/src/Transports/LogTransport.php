<?php

declare(strict_types=1);

namespace Hydra\Mail\Transports;

use Hydra\Mail\Address;
use Hydra\Mail\Contracts\TransportInterface;
use Hydra\Mail\Message;
use Psr\Log\LoggerInterface;

/**
 * Writes each message to the log instead of sending it.
 *
 * The bodies are logged as written rather than as rendered MIME: quoted-printable
 * turns every "=" in a link into "=3D", and a reset link copied out of the log
 * has to work.
 */
final readonly class LogTransport implements TransportInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function send(Message $message): void
    {
        $this->logger->info('Mail to {to}: {subject}', [
            'to' => implode(', ', $this->emails($message->recipients())),
            'subject' => $message->getSubject(),
            'from' => $message->getFrom()?->email,
            'cc' => $this->emails($message->getCc()),
            'bcc' => $this->emails($message->getBcc()),
            'text' => $message->getText(),
            'html' => $message->getHtml(),
        ]);
    }

    /**
     * @param list<Address> $addresses
     * @return list<string>
     */
    private function emails(array $addresses): array
    {
        return array_map(static fn (Address $a): string => $a->email, $addresses);
    }
}
