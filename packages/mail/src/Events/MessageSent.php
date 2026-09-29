<?php

declare(strict_types=1);

namespace Hydra\Mail\Events;

use Hydra\Mail\Message;

/**
 * A transport accepted a message. Dispatched only after the send returned, so
 * a listener that records it never records mail that did not go out; a send
 * that failed is the caller's (or the queue's) to report.
 */
final readonly class MessageSent
{
    public function __construct(
        /** As the transport received it: the default sender already filled in. */
        public Message $message,
        /** MailConfig::$transport (smtp, log or array), or '' when the mailer was built by hand. */
        public string $transport,
    ) {}
}
