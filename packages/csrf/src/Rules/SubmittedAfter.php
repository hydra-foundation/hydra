<?php

declare(strict_types=1);

namespace Hydra\Csrf\Rules;

use Hydra\Core\Security\Signer;
use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * The form was loaded long enough ago for a person to have filled it in, and
 * not so long ago that the page could be a copy a bot keeps replaying. A bot
 * posts within a second of fetching the page; nobody reads and fills a form
 * that fast.
 *
 * The start time travels in the form, signed, so the check needs no session
 * and a page served from a cache still works. Signed rather than plain: an
 * unsigned timestamp is one more field for the bot to fill with an old
 * number. The prefix keeps a signed start time from ever being mistaken for
 * anything else the app signs under the same key, and the reverse.
 *
 * Anything that is not a start time this app signed reads as expired, the
 * one answer a person can act on: load the form again and send it.
 */
final readonly class SubmittedAfter implements RuleInterface
{
    public const PREFIX = 'form-started:';

    public function __construct(
        private Signer $signer,
        private ClockInterface $clock,
        private int $minSeconds = 3,
        private int $maxAge = 86_400,
        private string $tooFast = 'That was quick. Please check your message and send it again.',
        private string $expired = 'This form has expired. Please send it again.',
    ) {
        if ($minSeconds < 0) {
            throw new InvalidArgumentException(sprintf('minSeconds must be 0 or more; got %d.', $minSeconds));
        }

        if ($maxAge <= $minSeconds) {
            throw new InvalidArgumentException(sprintf(
                'maxAge must be greater than minSeconds (%d); got %d.',
                $minSeconds,
                $maxAge,
            ));
        }
    }

    /** A signed start time for "now", the value the form carries. */
    public function stamp(): string
    {
        return $this->signer->sign(self::PREFIX . $this->clock->now()->getTimestamp());
    }

    public function validate(mixed $value, Context $context): ?string
    {
        $started = $this->started($value);

        if ($started === null) {
            return $this->expired;
        }

        $elapsed = $this->clock->now()->getTimestamp() - $started;

        return match (true) {
            $elapsed < 0, $elapsed > $this->maxAge => $this->expired,
            $elapsed < $this->minSeconds => $this->tooFast,
            default => null,
        };
    }

    private function started(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $message = $this->signer->verify($value);

        if ($message === null || !str_starts_with($message, self::PREFIX)) {
            return null;
        }

        $seconds = substr($message, strlen(self::PREFIX));

        return ctype_digit($seconds) ? (int) $seconds : null;
    }
}
