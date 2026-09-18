<?php

declare(strict_types=1);

namespace Hydra\Mail\Smtp;

use Hydra\Mail\Exceptions\TransportException;

/**
 * The connection an SMTP conversation runs over.
 */
interface StreamInterface
{
    /** @throws TransportException */
    public function write(string $data): void;

    /**
     * One line without its line ending.
     *
     * @throws TransportException when the connection closes or times out
     */
    public function readLine(): string;

    /** @throws TransportException */
    public function startTls(): void;

    public function close(): void;
}
