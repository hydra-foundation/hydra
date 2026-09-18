<?php

declare(strict_types=1);

namespace Hydra\Mail\Transports;

use Closure;
use Hydra\Mail\Contracts\TransportInterface;
use Hydra\Mail\Exceptions\TransportException;
use Hydra\Mail\MailConfig;
use Hydra\Mail\Message;
use Hydra\Mail\MimeRenderer;
use Hydra\Mail\Smtp\SocketStream;
use Hydra\Mail\Smtp\StreamInterface;
use InvalidArgumentException;

/**
 * Delivers over SMTP, one connection per message.
 *
 * Every recipient must be accepted before DATA is sent, so a refused address
 * fails the whole message rather than delivering it to some recipients.
 */
final class SmtpTransport implements TransportInterface
{
    /** @var Closure(MailConfig): StreamInterface */
    private Closure $connect;

    /** @param (Closure(MailConfig): StreamInterface)|null $connect */
    public function __construct(
        private readonly MailConfig $config,
        private readonly MimeRenderer $renderer = new MimeRenderer,
        ?Closure $connect = null,
    ) {
        $this->connect = $connect ?? static fn (MailConfig $c): StreamInterface => SocketStream::open(
            $c->host,
            $c->port,
            $c->encryption === MailConfig::SSL,
            $c->timeout,
        );
    }

    public function send(Message $message): void
    {
        $from = $message->getFrom() ?? throw new InvalidArgumentException('The message has no sender.');
        $data = $this->renderer->render($message);

        $stream = ($this->connect)($this->config);

        try {
            $this->expect($stream, 'the greeting', 220);
            $capabilities = $this->hello($stream);

            if ($this->config->encryption === MailConfig::TLS) {
                if (!isset($capabilities['STARTTLS'])) {
                    throw new TransportException(
                        "The SMTP server at {$this->config->host} does not offer STARTTLS, and MAIL_ENCRYPTION=tls will not send in the clear.",
                    );
                }

                $this->command($stream, 'STARTTLS', 'STARTTLS', 220);
                $stream->startTls();
                $capabilities = $this->hello($stream);
            }

            if ($this->config->username !== '') {
                $this->authenticate($stream, $capabilities);
            }

            $this->command($stream, "MAIL FROM:<{$from->email}>", 'the sender', 250);

            foreach ($message->recipients() as $recipient) {
                $this->command($stream, "RCPT TO:<{$recipient->email}>", "the recipient {$recipient->email}", 250, 251);
            }

            $this->command($stream, 'DATA', 'DATA', 354);
            $stream->write($this->dotStuff($data) . ".\r\n");
            $this->expect($stream, 'the message', 250);

            $this->quit($stream);
        } finally {
            $stream->close();
        }
    }

    /** @return array<string, string> capability keyword => its parameters */
    private function hello(StreamInterface $stream): array
    {
        $lines = $this->command($stream, "EHLO {$this->config->localDomain}", 'EHLO', 250);

        $capabilities = [];
        foreach (array_slice($lines, 1) as $line) {
            [$keyword, $parameters] = array_pad(explode(' ', $line, 2), 2, '');
            $capabilities[strtoupper($keyword)] = strtoupper($parameters);
        }

        return $capabilities;
    }

    /** @param array<string, string> $capabilities */
    private function authenticate(StreamInterface $stream, array $capabilities): void
    {
        $mechanisms = explode(' ', $capabilities['AUTH'] ?? '');
        $username = $this->config->username;
        $password = $this->config->password;

        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command($stream, 'AUTH PLAIN ' . base64_encode("\0{$username}\0{$password}"), 'the credentials', 235);

            return;
        }

        if (in_array('LOGIN', $mechanisms, true)) {
            $this->command($stream, 'AUTH LOGIN', 'AUTH LOGIN', 334);
            $this->command($stream, base64_encode($username), 'the username', 334);
            $this->command($stream, base64_encode($password), 'the credentials', 235);

            return;
        }

        throw new TransportException('The SMTP server offers neither AUTH PLAIN nor AUTH LOGIN.');
    }

    /**
     * The label names the step in an error, never the line sent: that line can
     * be a password.
     *
     * @return list<string>
     */
    private function command(StreamInterface $stream, string $line, string $label, int ...$accepted): array
    {
        $stream->write($line . "\r\n");

        return $this->expect($stream, $label, ...$accepted);
    }

    /** @return list<string> the reply's text, one entry per line */
    private function expect(StreamInterface $stream, string $label, int ...$accepted): array
    {
        $lines = [];

        do {
            $line = $stream->readLine();
            $code = (int) substr($line, 0, 3);
            $lines[] = substr($line, 4);
        } while (($line[3] ?? ' ') === '-' && count($lines) < 100);

        if (!in_array($code, $accepted, true)) {
            throw new TransportException(sprintf(
                'The SMTP server refused %s: %d %s',
                $label,
                $code,
                implode(' ', $lines),
            ));
        }

        return $lines;
    }

    private function quit(StreamInterface $stream): void
    {
        try {
            $this->command($stream, 'QUIT', 'QUIT', 221);
        } catch (TransportException) {
            // The message is already accepted; a server that hangs up without a goodbye has not lost it.
        }
    }

    /** A line starting with "." would otherwise end the message early. */
    private function dotStuff(string $data): string
    {
        $data = (string) preg_replace('/^\./m', '..', $data);

        return str_ends_with($data, "\r\n") ? $data : $data . "\r\n";
    }
}
