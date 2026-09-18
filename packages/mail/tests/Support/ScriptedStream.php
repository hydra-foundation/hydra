<?php

declare(strict_types=1);

namespace Hydra\Mail\Tests\Support;

use Hydra\Mail\Exceptions\TransportException;
use Hydra\Mail\Smtp\StreamInterface;

/**
 * An SMTP server's side of the conversation, played back line by line.
 */
final class ScriptedStream implements StreamInterface
{
    /** @var list<string> */
    public array $written = [];

    public bool $tls = false;

    public bool $closed = false;

    /** @param list<string> $replies */
    public function __construct(private array $replies) {}

    public function write(string $data): void
    {
        $this->written[] = $data;
    }

    public function readLine(): string
    {
        if ($this->replies === []) {
            throw new TransportException('The SMTP server closed the connection.');
        }

        return array_shift($this->replies);
    }

    public function startTls(): void
    {
        $this->tls = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** @return list<string> the command lines sent, without the message data */
    public function commands(): array
    {
        return array_values(array_map(
            static fn (string $w): string => rtrim($w, "\r\n"),
            array_filter($this->written, static fn (string $w): bool => !str_contains($w, "\r\n\r\n")),
        ));
    }

    public function data(): string
    {
        foreach ($this->written as $w) {
            if (str_contains($w, "\r\n\r\n")) {
                return $w;
            }
        }

        return '';
    }
}
