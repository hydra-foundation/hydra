<?php

declare(strict_types=1);

namespace Hydra\Mail\Smtp;

use Hydra\Mail\Exceptions\TransportException;

/**
 * A TCP connection to an SMTP server, verifying the server's certificate
 * whenever TLS is in use.
 */
final class SocketStream implements StreamInterface
{
    private const CRYPTO = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /** @param resource $socket */
    private function __construct(private $socket) {}

    /** @throws TransportException */
    public static function open(string $host, int $port, bool $implicitTls, float $timeout): self
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'crypto_method' => self::CRYPTO,
        ]]);

        $socket = @stream_socket_client(
            ($implicitTls ? 'tls://' : 'tcp://') . $host . ':' . $port,
            $code,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new TransportException("Could not connect to the SMTP server at {$host}:{$port}: {$error}");
        }

        stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1) * 1_000_000));

        return new self($socket);
    }

    public function write(string $data): void
    {
        $this->assertOpen();
        $remaining = $data;

        while ($remaining !== '') {
            $written = @fwrite($this->socket, $remaining);

            if ($written === false || $written === 0) {
                throw new TransportException('The SMTP connection closed while writing.');
            }

            $remaining = substr($remaining, $written);
        }
    }

    public function readLine(): string
    {
        $this->assertOpen();
        $line = @fgets($this->socket, 1024);

        if ($line === false) {
            throw new TransportException(stream_get_meta_data($this->socket)['timed_out']
                ? 'The SMTP server stopped answering.'
                : 'The SMTP server closed the connection.');
        }

        return rtrim($line, "\r\n");
    }

    public function startTls(): void
    {
        if (@stream_socket_enable_crypto($this->socket, true, self::CRYPTO) !== true) {
            throw new TransportException('The TLS handshake with the SMTP server failed.');
        }
    }

    private function assertOpen(): void
    {
        if (!is_resource($this->socket)) {
            throw new TransportException('The SMTP connection is already closed.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
