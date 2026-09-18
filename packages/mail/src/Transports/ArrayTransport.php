<?php

declare(strict_types=1);

namespace Hydra\Mail\Transports;

use Hydra\Mail\Contracts\TransportInterface;
use Hydra\Mail\Message;

/**
 * Keeps every message in memory and delivers none of them.
 */
final class ArrayTransport implements TransportInterface
{
    /** @var list<Message> */
    private array $messages = [];

    public function send(Message $message): void
    {
        $this->messages[] = $message;
    }

    /** @return list<Message> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function flush(): void
    {
        $this->messages = [];
    }
}
