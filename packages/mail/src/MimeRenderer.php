<?php

declare(strict_types=1);

namespace Hydra\Mail;

use InvalidArgumentException;

/**
 * Renders a message as RFC 5322 text with CRLF line endings.
 *
 * Bodies are quoted-printable, so no line exceeds the 998-octet limit whatever
 * was written. Bcc is never rendered: it exists only in the envelope.
 */
final class MimeRenderer
{
    private const CRLF = "\r\n";

    public function render(Message $message): string
    {
        $from = $message->getFrom() ?? throw new InvalidArgumentException('The message has no sender.');

        $headers = [
            'Date' => date(DATE_RFC2822),
            'Message-ID' => sprintf('<%s@%s>', bin2hex(random_bytes(16)), $from->domain()),
            'From' => $this->address($from),
        ];

        if ($message->getReplyTo() !== null) {
            $headers['Reply-To'] = $this->address($message->getReplyTo());
        }

        if ($message->getTo() !== []) {
            $headers['To'] = $this->addresses($message->getTo());
        }

        if ($message->getCc() !== []) {
            $headers['Cc'] = $this->addresses($message->getCc());
        }

        $headers['Subject'] = $this->encode($message->getSubject());
        $headers['MIME-Version'] = '1.0';

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode(self::CRLF, $lines) . self::CRLF . $this->body($message);
    }

    private function body(Message $message): string
    {
        $text = $message->getText();
        $html = $message->getHtml();

        if ($text !== null && $html !== null) {
            // "=_" cannot occur in quoted-printable output, so no part can contain the boundary.
            $boundary = '=_' . bin2hex(random_bytes(12));

            return 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . self::CRLF
                . self::CRLF
                . '--' . $boundary . self::CRLF
                . $this->part('text/plain', $text)
                . '--' . $boundary . self::CRLF
                . $this->part('text/html', $html)
                . '--' . $boundary . '--' . self::CRLF;
        }

        return $html !== null
            ? $this->part('text/html', $html)
            : $this->part('text/plain', $text ?? '');
    }

    private function part(string $type, string $content): string
    {
        $normalised = (string) preg_replace('/\r\n|\r|\n/', self::CRLF, $content);

        return "Content-Type: {$type}; charset=utf-8" . self::CRLF
            . 'Content-Transfer-Encoding: quoted-printable' . self::CRLF
            . self::CRLF
            . quoted_printable_encode($normalised) . self::CRLF;
    }

    /** @param list<Address> $addresses */
    private function addresses(array $addresses): string
    {
        return implode(',' . self::CRLF . ' ', array_map($this->address(...), $addresses));
    }

    private function address(Address $address): string
    {
        if ($address->name === '') {
            return $address->email;
        }

        $name = $this->isAscii($address->name)
            ? '"' . addcslashes($address->name, '"\\') . '"'
            : $this->encode($address->name);

        return "{$name} <{$address->email}>";
    }

    /**
     * RFC 2047 encoded words for anything outside printable ASCII, split on
     * character boundaries so no word ends in the middle of a UTF-8 sequence.
     */
    private function encode(string $value): string
    {
        if ($this->isAscii($value)) {
            return $value;
        }

        $words = [];
        $chunk = '';

        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($value) as $character) {
            if (strlen($chunk . $character) > 45) {
                $words[] = $chunk;
                $chunk = '';
            }
            $chunk .= $character;
        }
        $words[] = $chunk;

        return implode(self::CRLF . ' ', array_map(
            static fn (string $word): string => '=?UTF-8?B?' . base64_encode($word) . '?=',
            $words,
        ));
    }

    private function isAscii(string $value): bool
    {
        return preg_match('/[^\x20-\x7E]/', $value) === 0;
    }
}
