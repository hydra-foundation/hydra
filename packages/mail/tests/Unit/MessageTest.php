<?php

declare(strict_types=1);

namespace Hydra\Mail\Tests\Unit;

use Hydra\Mail\Address;
use Hydra\Mail\Message;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Message::class)]
#[CoversClass(Address::class)]
final class MessageTest extends TestCase
{
    public function test_every_call_returns_a_copy(): void
    {
        $original = Message::make()->to('ada@example.com');
        $changed = $original->to('grace@example.com')->subject('Hi');

        $this->assertCount(1, $original->getTo());
        $this->assertSame('', $original->getSubject());
        $this->assertCount(2, $changed->getTo());
    }

    public function test_it_holds_what_it_was_given(): void
    {
        $message = Message::make()
            ->from('app@example.com', 'App')
            ->replyTo('help@example.com')
            ->to('ada@example.com', 'Ada')
            ->cc('grace@example.com')
            ->bcc('alan@example.com')
            ->subject('Welcome')
            ->text('plain')
            ->html('<p>html</p>');

        $this->assertEquals(new Address('app@example.com', 'App'), $message->getFrom());
        $this->assertEquals(new Address('help@example.com'), $message->getReplyTo());
        $this->assertSame('ada@example.com', $message->getTo()[0]->email);
        $this->assertSame('Ada', $message->getTo()[0]->name);
        $this->assertSame('grace@example.com', $message->getCc()[0]->email);
        $this->assertSame('alan@example.com', $message->getBcc()[0]->email);
        $this->assertSame('Welcome', $message->getSubject());
        $this->assertSame('plain', $message->getText());
        $this->assertSame('<p>html</p>', $message->getHtml());
    }

    public function test_recipients_include_every_field_bcc_too(): void
    {
        $message = Message::make()->to('a@example.com')->cc('b@example.com')->bcc('c@example.com');

        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            array_map(static fn (Address $a): string => $a->email, $message->recipients()),
        );
    }

    public function test_is_for_ignores_case_and_looks_in_every_field(): void
    {
        $message = Message::make()->to('a@example.com')->bcc('Hidden@Example.com');

        $this->assertTrue($message->isFor('hidden@example.com'));
        $this->assertFalse($message->isFor('nobody@example.com'));
    }

    /** @return iterable<string, array{string}> */
    public static function injections(): iterable
    {
        yield 'line feed' => ["Hi\nBcc: victim@example.com"];
        yield 'carriage return' => ["Hi\rBcc: victim@example.com"];
    }

    #[DataProvider('injections')]
    public function test_a_subject_cannot_smuggle_in_a_header(string $subject): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::make()->subject($subject);
    }

    #[DataProvider('injections')]
    public function test_a_name_cannot_smuggle_in_a_header(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::make()->to('a@example.com', $name);
    }

    public function test_an_address_must_be_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"not-an-address" is not an email address.');

        new Address('not-an-address');
    }

    public function test_an_address_rejects_a_line_break_hidden_in_the_email(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Address("a@example.com\r\nBcc: victim@example.com");
    }

    public function test_the_domain_is_after_the_last_at(): void
    {
        $this->assertSame('example.com', (new Address('"a@b"@example.com'))->domain());
    }
}
