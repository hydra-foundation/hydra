<?php

declare(strict_types=1);

namespace Hydra\Mail\Tests\Unit;

use Hydra\Mail\Exceptions\TransportException;
use Hydra\Mail\Smtp\SocketStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Against a real listening socket on loopback. The client connects into the
 * listen backlog, so one process can play both ends.
 */
#[CoversClass(SocketStream::class)]
final class SocketStreamTest extends TestCase
{
    /** @var resource */
    private $server;

    private int $port;

    protected function setUp(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        $this->assertNotFalse($server, $error);
        $this->server = $server;
        $this->port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);
    }

    protected function tearDown(): void
    {
        fclose($this->server);
    }

    /** @return array{SocketStream, resource} */
    private function connect(float $timeout = 2.0): array
    {
        $client = SocketStream::open('127.0.0.1', $this->port, false, $timeout);
        $peer = stream_socket_accept($this->server, 2);
        $this->assertNotFalse($peer);

        return [$client, $peer];
    }

    public function test_it_reads_lines_without_their_endings_and_writes_whole_commands(): void
    {
        [$client, $peer] = $this->connect();

        fwrite($peer, "220-first\r\n220 second\r\n");
        $this->assertSame('220-first', $client->readLine());
        $this->assertSame('220 second', $client->readLine());

        $client->write("EHLO app.test\r\n");
        $this->assertSame("EHLO app.test\r\n", fgets($peer));

        $client->close();
        fclose($peer);
    }

    public function test_a_closed_connection_is_reported_as_closed(): void
    {
        [$client, $peer] = $this->connect();
        fclose($peer);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('closed the connection');

        $client->readLine();
    }

    public function test_a_silent_server_times_out_rather_than_hanging(): void
    {
        [$client, $peer] = $this->connect(0.2);

        try {
            $client->readLine();
            $this->fail('The read returned.');
        } catch (TransportException $e) {
            $this->assertSame('The SMTP server stopped answering.', $e->getMessage());
        } finally {
            fclose($peer);
        }
    }

    public function test_nothing_listening_is_a_transport_error_naming_the_address(): void
    {
        fclose($this->server);
        $this->server = stream_socket_server('tcp://127.0.0.1:0') ?: throw new \RuntimeException('no socket');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage("Could not connect to the SMTP server at 127.0.0.1:{$this->port}");

        SocketStream::open('127.0.0.1', $this->port, false, 1.0);
    }

    public function test_a_failed_handshake_is_a_transport_error(): void
    {
        [$client, $peer] = $this->connect(0.5);
        fwrite($peer, "not tls at all\r\n");
        fclose($peer);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The TLS handshake with the SMTP server failed.');

        $client->startTls();
    }

    public function test_writing_to_a_closed_connection_is_an_error(): void
    {
        [$client, $peer] = $this->connect();
        $client->close();

        $this->expectException(TransportException::class);

        try {
            $client->write('x');
        } finally {
            fclose($peer);
        }
    }
}
