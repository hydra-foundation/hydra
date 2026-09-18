<?php

declare(strict_types=1);

namespace Hydra\Mail;

use InvalidArgumentException;

/**
 * An email, built fluently. Every call returns a copy, so a message that has
 * been sent cannot be changed underneath whatever recorded it.
 */
final class Message
{
    private ?Address $from = null;

    /** @var list<Address> */
    private array $to = [];

    /** @var list<Address> */
    private array $cc = [];

    /** @var list<Address> */
    private array $bcc = [];

    private ?Address $replyTo = null;

    private string $subject = '';

    private ?string $text = null;

    private ?string $html = null;

    public static function make(): self
    {
        return new self;
    }

    public function from(string $email, string $name = ''): self
    {
        $copy = clone $this;
        $copy->from = new Address($email, $name);

        return $copy;
    }

    public function to(string $email, string $name = ''): self
    {
        $copy = clone $this;
        $copy->to[] = new Address($email, $name);

        return $copy;
    }

    public function cc(string $email, string $name = ''): self
    {
        $copy = clone $this;
        $copy->cc[] = new Address($email, $name);

        return $copy;
    }

    public function bcc(string $email, string $name = ''): self
    {
        $copy = clone $this;
        $copy->bcc[] = new Address($email, $name);

        return $copy;
    }

    public function replyTo(string $email, string $name = ''): self
    {
        $copy = clone $this;
        $copy->replyTo = new Address($email, $name);

        return $copy;
    }

    public function subject(string $subject): self
    {
        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new InvalidArgumentException('A subject cannot contain a line break.');
        }

        $copy = clone $this;
        $copy->subject = $subject;

        return $copy;
    }

    public function text(string $body): self
    {
        $copy = clone $this;
        $copy->text = $body;

        return $copy;
    }

    public function html(string $body): self
    {
        $copy = clone $this;
        $copy->html = $body;

        return $copy;
    }

    public function getFrom(): ?Address
    {
        return $this->from;
    }

    /** @return list<Address> */
    public function getTo(): array
    {
        return $this->to;
    }

    /** @return list<Address> */
    public function getCc(): array
    {
        return $this->cc;
    }

    /** @return list<Address> */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function getReplyTo(): ?Address
    {
        return $this->replyTo;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    /**
     * Everyone the message is delivered to, Bcc included.
     *
     * @return list<Address>
     */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    /** Whether any recipient, in any field, is this address. */
    public function isFor(string $email): bool
    {
        foreach ($this->recipients() as $address) {
            if (strcasecmp($address->email, $email) === 0) {
                return true;
            }
        }

        return false;
    }
}
