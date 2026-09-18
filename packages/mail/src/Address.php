<?php

declare(strict_types=1);

namespace Hydra\Mail;

use InvalidArgumentException;

/**
 * One mailbox: an address and, optionally, the name shown beside it.
 */
final readonly class Address
{
    public function __construct(
        public string $email,
        public string $name = '',
    ) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("\"{$email}\" is not an email address.");
        }

        if (preg_match('/[\r\n]/', $name) === 1) {
            throw new InvalidArgumentException('A mailbox name cannot contain a line break.');
        }
    }

    public function domain(): string
    {
        return substr($this->email, strrpos($this->email, '@') + 1);
    }
}
