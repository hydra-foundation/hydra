<?php

declare(strict_types=1);

namespace Hydra\Mail\Tests\Unit;

use Hydra\Event\Testing\FakeDispatcher;
use Hydra\Log\Testing\CapturingLogger;
use Hydra\Mail\Address;
use Hydra\Mail\Contracts\TransportInterface;
use Hydra\Mail\Events\MessageSent;
use Hydra\Mail\Mailer;
use Hydra\Mail\Message;
use Hydra\Mail\Transports\ArrayTransport;
use Hydra\Mail\Transports\LogTransport;
use Hydra\Mail\Exceptions\TransportException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Mailer::class)]
#[CoversClass(ArrayTransport::class)]
#[CoversClass(LogTransport::class)]
#[CoversClass(MessageSent::class)]
final class MailerTest extends TestCase
{
    public function test_a_message_without_a_sender_gets_the_default(): void
    {
        $transport = new ArrayTransport;
        (new Mailer($transport, new Address('app@example.com', 'App')))->send(Message::make()->to('a@example.com')->text('x'));

        $this->assertEquals(new Address('app@example.com', 'App'), $transport->messages()[0]->getFrom());
    }

    public function test_a_message_with_a_sender_keeps_it(): void
    {
        $transport = new ArrayTransport;
        (new Mailer($transport, new Address('app@example.com')))->send(Message::make()->from('me@example.com')->to('a@example.com')->text('x'));

        $this->assertSame('me@example.com', $transport->messages()[0]->getFrom()?->email);
    }

    public function test_no_sender_anywhere_is_an_error(): void
    {
        $this->expectExceptionMessage('The message has no sender, and MAIL_FROM_ADDRESS is not set.');

        (new Mailer(new ArrayTransport))->send(Message::make()->to('a@example.com')->text('x'));
    }

    public function test_no_recipient_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The message has no recipients.');

        (new Mailer(new ArrayTransport))->send(Message::make()->from('a@example.com')->text('x'));
    }

    public function test_bcc_alone_is_a_recipient(): void
    {
        $transport = new ArrayTransport;
        (new Mailer($transport))->send(Message::make()->from('a@example.com')->bcc('b@example.com')->text('x'));

        $this->assertCount(1, $transport->messages());
    }

    public function test_no_body_is_an_error(): void
    {
        $this->expectExceptionMessage('The message has no body.');

        (new Mailer(new ArrayTransport))->send(Message::make()->from('a@example.com')->to('b@example.com'));
    }

    public function test_the_array_transport_can_be_emptied(): void
    {
        $transport = new ArrayTransport;
        $transport->send(Message::make());
        $transport->flush();

        $this->assertSame([], $transport->messages());
    }

    public function test_the_log_transport_keeps_a_link_intact(): void
    {
        $logger = new CapturingLogger;
        (new Mailer(new LogTransport($logger), new Address('app@example.com')))->send(
            Message::make()->to('ada@example.com')->bcc('b@example.com')->subject('Reset')->text('https://x.test/r?token=abc'),
        );

        $this->assertCount(1, $logger->records());
        $record = $logger->records()[0];
        $this->assertSame('info', $record['level']);
        $this->assertSame('Mail to {to}: {subject}', $record['message']);
        $this->assertSame('ada@example.com, b@example.com', $record['context']['to']);
        $this->assertSame(['b@example.com'], $record['context']['bcc']);
        $this->assertSame('app@example.com', $record['context']['from']);
        $this->assertSame('https://x.test/r?token=abc', $record['context']['text']);
    }

    public function test_a_sent_message_is_announced_as_sent_with_the_transport_name(): void
    {
        $events = new FakeDispatcher;
        $transport = new ArrayTransport;
        (new Mailer($transport, new Address('app@example.com', 'App'), $events, 'smtp'))
            ->send(Message::make()->to('a@example.com')->subject('Hi')->text('x'));

        $this->assertCount(1, $events->dispatched());
        $sent = $events->first(MessageSent::class);
        $this->assertSame('smtp', $sent->transport);
        $this->assertSame($transport->messages()[0], $sent->message);
        $this->assertEquals(new Address('app@example.com', 'App'), $sent->message->getFrom());
    }

    public function test_the_transport_name_defaults_to_empty(): void
    {
        $events = new FakeDispatcher;
        (new Mailer(new ArrayTransport, new Address('app@example.com'), $events))
            ->send(Message::make()->to('a@example.com')->text('x'));

        $this->assertSame('', $events->first(MessageSent::class)->transport);
    }

    public function test_a_message_that_fails_validation_is_not_announced(): void
    {
        $events = new FakeDispatcher;

        try {
            (new Mailer(new ArrayTransport, new Address('app@example.com'), $events, 'array'))
                ->send(Message::make()->text('x'));
            $this->fail('Expected the send to be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame([], $events->dispatched());
    }

    public function test_a_message_the_transport_refuses_is_not_announced(): void
    {
        $events = new FakeDispatcher;
        $refusing = new class implements TransportInterface {
            public function send(Message $message): void
            {
                throw new TransportException('535 Authentication failed');
            }
        };

        try {
            (new Mailer($refusing, new Address('app@example.com'), $events, 'smtp'))
                ->send(Message::make()->to('a@example.com')->text('x'));
            $this->fail('Expected the transport to throw.');
        } catch (TransportException $e) {
            $this->assertSame('535 Authentication failed', $e->getMessage());
        }

        $this->assertSame([], $events->dispatched());
    }
}
