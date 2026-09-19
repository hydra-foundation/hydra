<?php

declare(strict_types=1);

namespace Hydra\Mail\Tests\Unit;

use Hydra\Core\Testing\FrozenClock;
use Hydra\Mail\Message;
use Hydra\Mail\MimeRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MimeRenderer::class)]
final class MimeRendererTest extends TestCase
{
    private function message(): Message
    {
        return Message::make()->from('app@example.com', 'The App')->to('ada@example.com')->subject('Hello');
    }

    public function test_the_date_header_is_the_clocks(): void
    {
        $mime = (new MimeRenderer(new FrozenClock('2026-03-04T05:06:07+00:00')))->render($this->message()->text('Hi'));

        $this->assertStringStartsWith("Date: Wed, 04 Mar 2026 05:06:07 +0000\r\n", $mime);
    }

    public function test_a_text_message_is_one_quoted_printable_part(): void
    {
        $mime = (new MimeRenderer)->render($this->message()->text('Reset: https://x.test/r?token=abc'));

        $this->assertStringContainsString("Content-Type: text/plain; charset=utf-8\r\n", $mime);
        $this->assertStringContainsString("Content-Transfer-Encoding: quoted-printable\r\n", $mime);
        $this->assertStringContainsString('token=3Dabc', $mime);
        $this->assertStringNotContainsString('multipart', $mime);
    }

    public function test_an_html_message_is_one_html_part(): void
    {
        $mime = (new MimeRenderer)->render($this->message()->html('<p>Hi</p>'));

        $this->assertStringContainsString("Content-Type: text/html; charset=utf-8\r\n", $mime);
        $this->assertStringNotContainsString('text/plain', $mime);
    }

    public function test_text_and_html_are_alternatives_text_first(): void
    {
        $mime = (new MimeRenderer)->render($this->message()->text('plain')->html('<p>html</p>'));

        $this->assertMatchesRegularExpression('/Content-Type: multipart\/alternative; boundary="(=_[0-9a-f]{24})"/', $mime);
        preg_match('/boundary="([^"]+)"/', $mime, $m);
        $boundary = $m[1];

        $this->assertSame(3, substr_count($mime, '--' . $boundary));
        $this->assertStringEndsWith('--' . $boundary . "--\r\n", $mime);
        $this->assertLessThan(strpos($mime, 'text/html'), strpos($mime, 'text/plain'));
    }

    public function test_the_headers_come_first_and_name_everyone_but_bcc(): void
    {
        $mime = (new MimeRenderer)->render(
            $this->message()->cc('grace@example.com', 'Grace')->bcc('secret@example.com')->replyTo('help@example.com')->text('x'),
        );

        $this->assertMatchesRegularExpression('/^Date: .+\r\nMessage-ID: <[0-9a-f]{32}@example\.com>\r\nFrom: "The App" <app@example\.com>\r\n/', $mime);
        $this->assertStringContainsString("Reply-To: help@example.com\r\n", $mime);
        $this->assertStringContainsString("To: ada@example.com\r\n", $mime);
        $this->assertStringContainsString("Cc: \"Grace\" <grace@example.com>\r\n", $mime);
        $this->assertStringContainsString("Subject: Hello\r\nMIME-Version: 1.0\r\n", $mime);
        $this->assertStringNotContainsString('secret@example.com', $mime);
    }

    public function test_several_recipients_are_folded_onto_separate_lines(): void
    {
        $mime = (new MimeRenderer)->render($this->message()->to('grace@example.com')->text('x'));

        $this->assertStringContainsString("To: ada@example.com,\r\n grace@example.com\r\n", $mime);
    }

    public function test_quotes_in_a_name_are_escaped(): void
    {
        $mime = (new MimeRenderer)->render(Message::make()->from('a@example.com', 'Ada "The Countess" \\ L')->to('b@example.com')->text('x'));

        $this->assertStringContainsString('From: "Ada \"The Countess\" \\\\ L" <a@example.com>', $mime);
    }

    public function test_a_non_ascii_subject_and_name_are_encoded_words(): void
    {
        $mime = (new MimeRenderer)->render(Message::make()->from('a@example.com', 'Zoë')->to('b@example.com')->subject('Café ☕')->text('x'));

        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Café ☕') . "?=\r\n", $mime);
        $this->assertStringContainsString('From: =?UTF-8?B?' . base64_encode('Zoë') . '?= <a@example.com>', $mime);
    }

    public function test_a_long_encoded_subject_splits_between_characters(): void
    {
        $subject = str_repeat('é', 40);
        $mime = (new MimeRenderer)->render($this->message()->subject($subject)->text('x'));

        preg_match('/^Subject: (.+?)\r\nMIME/ms', $mime, $m);
        preg_match_all('/=\?UTF-8\?B\?([^?]+)\?=/', $m[1], $words);

        $this->assertGreaterThan(1, count($words[1]));
        foreach ($words[1] as $word) {
            $this->assertSame(1, preg_match('//u', base64_decode($word)));
            $this->assertLessThanOrEqual(75, strlen('=?UTF-8?B?' . $word . '?='));
        }
        $this->assertSame($subject, implode('', array_map('base64_decode', $words[1])));
    }

    public function test_line_endings_become_crlf_and_no_line_runs_long(): void
    {
        $mime = (new MimeRenderer)->render($this->message()->text("one\ntwo\rthree\r\n" . str_repeat('x', 2000)));

        $this->assertStringContainsString("one\r\ntwo\r\nthree\r\n", $mime);
        $this->assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $mime);
        foreach (explode("\r\n", $mime) as $line) {
            $this->assertLessThanOrEqual(998, strlen($line));
        }
    }

    public function test_a_message_without_a_sender_cannot_render(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MimeRenderer)->render(Message::make()->to('a@example.com')->text('x'));
    }
}
