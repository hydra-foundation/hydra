<?php

declare(strict_types=1);

namespace Hydra\Mail\Tests\Unit;

use Hydra\Mail\Message;
use Hydra\Mail\Testing\FakeMailer;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeMailer::class)]
final class FakeMailerTest extends TestCase
{
    private FakeMailer $mailer;

    protected function setUp(): void
    {
        $this->mailer = new FakeMailer;
        $this->mailer->send(Message::make()->to('ada@example.com')->subject('Welcome'));
        $this->mailer->send(Message::make()->to('ada@example.com')->subject('Reset'));
        $this->mailer->send(Message::make()->bcc('grace@example.com')->subject('Welcome'));
    }

    private static function subject(string $subject): \Closure
    {
        return static fn (Message $m): bool => $m->getSubject() === $subject;
    }

    public function test_sent_filters(): void
    {
        $this->assertCount(3, $this->mailer->sent());
        $this->assertCount(2, $this->mailer->sent(self::subject('Welcome')));
    }

    public function test_the_passing_assertions_pass(): void
    {
        $this->mailer->assertSent();
        $this->mailer->assertSent(self::subject('Reset'));
        $this->mailer->assertSent(self::subject('Welcome'), 2);
        $this->mailer->assertNotSent(self::subject('Goodbye'));
        $this->mailer->assertSentTo('grace@example.com');
        (new FakeMailer)->assertNothingSent();
    }

    public function test_assert_sent_fails_when_nothing_matched(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No matching message was sent.');

        $this->mailer->assertSent(self::subject('Goodbye'));
    }

    public function test_assert_sent_fails_on_the_wrong_count(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected 1 matching messages; 2 were sent.');

        $this->mailer->assertSent(self::subject('Welcome'), 1);
    }

    public function test_assert_not_sent_fails_when_one_matched(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->mailer->assertNotSent(self::subject('Reset'));
    }

    public function test_assert_sent_to_fails_for_a_stranger(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->mailer->assertSentTo('alan@example.com');
    }

    public function test_assert_nothing_sent_fails_when_something_was(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('3 messages were sent.');

        $this->mailer->assertNothingSent();
    }
}
