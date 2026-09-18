<?php

declare(strict_types=1);

namespace Hydra\Mail\Tests\Unit;

use Hydra\Mail\Exceptions\TransportException;
use Hydra\Mail\MailConfig;
use Hydra\Mail\Message;
use Hydra\Mail\Tests\Support\ScriptedStream;
use Hydra\Mail\Transports\SmtpTransport;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SmtpTransport::class)]
final class SmtpTransportTest extends TestCase
{
    private const EHLO = ['250-mail.example.com', '250-PIPELINING', '250-STARTTLS', '250 AUTH LOGIN PLAIN'];

    private function message(): Message
    {
        return Message::make()
            ->from('app@example.com')
            ->to('ada@example.com')
            ->cc('grace@example.com')
            ->bcc('alan@example.com')
            ->subject('Hi')
            ->text('Hello');
    }

    /** @param list<string> $replies */
    private function send(array $replies, MailConfig $config, ?Message $message = null): ScriptedStream
    {
        $stream = new ScriptedStream($replies);
        (new SmtpTransport($config, connect: static fn (): ScriptedStream => $stream))->send($message ?? $this->message());

        return $stream;
    }

    /** @param list<string> $replies */
    private function refused(array $replies, MailConfig $config): ScriptedStream
    {
        $stream = new ScriptedStream($replies);

        try {
            (new SmtpTransport($config, connect: static fn (): ScriptedStream => $stream))->send($this->message());
            $this->fail('The send succeeded.');
        } catch (TransportException $e) {
            $this->lastError = $e->getMessage();
        }

        return $stream;
    }

    private string $lastError = '';

    private function plain(): MailConfig
    {
        return new MailConfig(host: 'mail.example.com', port: 1025, encryption: MailConfig::NONE, localDomain: 'app.test');
    }

    public function test_a_plain_conversation_delivers_to_every_recipient(): void
    {
        $stream = $this->send(
            ['220 hi', ...self::EHLO, '250 ok', '250 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye'],
            $this->plain(),
        );

        $this->assertSame([
            'EHLO app.test',
            'MAIL FROM:<app@example.com>',
            'RCPT TO:<ada@example.com>',
            'RCPT TO:<grace@example.com>',
            'RCPT TO:<alan@example.com>',
            'DATA',
            'QUIT',
        ], $stream->commands());
        $this->assertStringEndsWith("\r\n.\r\n", $stream->data());
        $this->assertStringNotContainsString('alan@example.com', $stream->data());
        $this->assertTrue($stream->closed);
    }

    public function test_starttls_upgrades_and_greets_again_before_anything_else(): void
    {
        $config = new MailConfig(host: 'mail.example.com', encryption: MailConfig::TLS, localDomain: 'app.test');

        $stream = $this->send(
            ['220 hi', ...self::EHLO, '220 go ahead', ...self::EHLO, '250 ok', '250 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye'],
            $config,
        );

        $this->assertTrue($stream->tls);
        $this->assertSame(['EHLO app.test', 'STARTTLS', 'EHLO app.test', 'MAIL FROM:<app@example.com>'], array_slice($stream->commands(), 0, 4));
    }

    public function test_tls_is_never_silently_skipped(): void
    {
        $config = new MailConfig(host: 'mail.example.com', encryption: MailConfig::TLS);

        $stream = $this->refused(['220 hi', '250-mail.example.com', '250 AUTH PLAIN'], $config);

        $this->assertStringContainsString('does not offer STARTTLS', $this->lastError);
        $this->assertNotContains('MAIL FROM:<app@example.com>', $stream->commands());
        $this->assertTrue($stream->closed);
    }

    public function test_implicit_tls_does_not_ask_for_starttls(): void
    {
        $config = new MailConfig(host: 'mail.example.com', port: 465, encryption: MailConfig::SSL);

        $stream = $this->send(['220 hi', '250 mail.example.com', '250 ok', '250 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye'], $config);

        $this->assertNotContains('STARTTLS', $stream->commands());
    }

    public function test_auth_plain_is_preferred(): void
    {
        $config = new MailConfig(host: 'h', encryption: MailConfig::SSL, username: 'user', password: 'secret');

        $stream = $this->send(['220 hi', '250-h', '250 AUTH LOGIN PLAIN', '235 ok', '250 ok', '250 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye'], $config);

        $this->assertContains('AUTH PLAIN ' . base64_encode("\0user\0secret"), $stream->commands());
    }

    public function test_auth_login_when_plain_is_not_offered(): void
    {
        $config = new MailConfig(host: 'h', encryption: MailConfig::SSL, username: 'user', password: 'secret');

        $stream = $this->send(['220 hi', '250-h', '250 AUTH LOGIN', '334 VXNlcm5hbWU6', '334 UGFzc3dvcmQ6', '235 ok', '250 ok', '250 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye'], $config);

        $this->assertSame(['AUTH LOGIN', base64_encode('user'), base64_encode('secret')], array_slice($stream->commands(), 1, 3));
    }

    public function test_no_usable_auth_mechanism_is_an_error(): void
    {
        $config = new MailConfig(host: 'h', encryption: MailConfig::SSL, username: 'user', password: 'secret');

        $this->refused(['220 hi', '250-h', '250 AUTH CRAM-MD5'], $config);

        $this->assertStringContainsString('neither AUTH PLAIN nor AUTH LOGIN', $this->lastError);
    }

    public function test_a_refused_password_is_never_in_the_error(): void
    {
        $config = new MailConfig(host: 'h', encryption: MailConfig::SSL, username: 'user', password: 'hunter2');

        $this->refused(['220 hi', '250-h', '250 AUTH PLAIN', '535 5.7.8 bad credentials'], $config);

        $this->assertSame('The SMTP server refused the credentials: 535 5.7.8 bad credentials', $this->lastError);
        $this->assertStringNotContainsString(base64_encode("\0user\0hunter2"), $this->lastError);
    }

    public function test_one_refused_recipient_means_no_data_is_sent(): void
    {
        $stream = $this->refused(['220 hi', '250 h', '250 ok', '250 ok', '550 no such user'], $this->plain());

        $this->assertSame('The SMTP server refused the recipient grace@example.com: 550 no such user', $this->lastError);
        $this->assertNotContains('DATA', $stream->commands());
        $this->assertTrue($stream->closed);
    }

    public function test_a_bad_greeting_stops_the_conversation(): void
    {
        $stream = $this->refused(['554 go away'], $this->plain());

        $this->assertStringContainsString('refused the greeting: 554 go away', $this->lastError);
        $this->assertSame([], $stream->commands());
    }

    public function test_a_rejected_message_body_is_an_error(): void
    {
        $this->refused(['220 hi', '250 h', '250 ok', '250 ok', '250 ok', '250 ok', '354 go', '552 too big'], $this->plain());

        $this->assertStringContainsString('refused the message: 552 too big', $this->lastError);
    }

    public function test_a_server_hanging_up_after_acceptance_still_counts_as_sent(): void
    {
        $stream = $this->send(['220 hi', '250 h', '250 ok', '250 ok', '250 ok', '250 ok', '354 go', '250 queued'], $this->plain());

        $this->assertTrue($stream->closed);
    }

    public function test_a_line_starting_with_a_dot_is_stuffed(): void
    {
        $stream = $this->send(
            ['220 hi', '250 h', '250 ok', '250 ok', '354 go', '250 queued', '221 bye'],
            $this->plain(),
            Message::make()->from('a@example.com')->to('b@example.com')->text(".\n.hidden\nend"),
        );

        $this->assertStringContainsString("\r\n..\r\n..hidden\r\nend", $stream->data());
    }

    public function test_a_message_without_a_sender_never_connects(): void
    {
        $connected = false;
        $transport = new SmtpTransport($this->plain(), connect: static function () use (&$connected): ScriptedStream {
            $connected = true;

            return new ScriptedStream([]);
        });

        try {
            $transport->send(Message::make()->to('a@example.com')->text('x'));
            $this->fail('The send succeeded.');
        } catch (InvalidArgumentException) {
        }

        $this->assertFalse($connected);
    }
}
