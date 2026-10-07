<?php

declare(strict_types=1);

namespace Hydra\Csrf;

use Hydra\Core\Security\Signer;
use Hydra\Csrf\Rules\SubmittedAfter;
use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Result;
use Hydra\Validation\Rules\Honeypot as HoneypotRule;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Two traps for the bots that post to public forms, printed together and
 * checked together: a field people never see, which a bot fills, and a
 * signed start time, which catches the bot that posts the instant it has the
 * page, or keeps replaying one it fetched long ago.
 *
 * The CSRF token stops none of this: a bot that fetches the form gets a valid
 * token like anyone else. Neither trap asks a person to do anything.
 */
final readonly class Honeypot
{
    /** The hidden input carrying the signed start time. */
    public const STARTED = '_started';

    private SubmittedAfter $timer;

    public function __construct(
        Signer $signer,
        ClockInterface $clock,
        private string $field = 'website',
        int $minSeconds = 3,
        int $maxAge = 86_400,
    ) {
        if ($field === '' || $field === self::STARTED) {
            throw new InvalidArgumentException(sprintf(
                'The trap needs a name of its own; got "%s".',
                $field,
            ));
        }

        $this->timer = new SubmittedAfter($signer, $clock, $minSeconds, $maxAge);
    }

    /**
     * The trap and a fresh start time, for inside the form. Hidden by a class
     * and not a style attribute, which a content policy without
     * 'unsafe-inline' would drop, showing the field. The app's stylesheet
     * owns `.form-trap`. The label is for the case where it still shows.
     */
    public function markup(): string
    {
        return sprintf(
            '<div class="form-trap" aria-hidden="true">'
            . '<label>Leave this empty <input type="text" name="%s" value="" tabindex="-1" autocomplete="off"></label>'
            . '</div>'
            . '<input type="hidden" name="%s" value="%s">',
            $this->escape($this->field),
            self::STARTED,
            $this->escape($this->timer->stamp()),
        );
    }

    /**
     * The checks for both fields, to spread into a form's own rules.
     *
     * @return array<string, list<RuleInterface>>
     */
    public function rules(): array
    {
        return [
            $this->field => [new HoneypotRule],
            self::STARTED => [$this->timer],
        ];
    }

    /**
     * What to show above the form when a trap failed, or null. Neither field
     * is visible, so their messages have nowhere else to go. The trap's
     * message wins: a filled trap says more than a quick post does.
     */
    public function error(Result $result): ?string
    {
        return $result->first($this->field) ?? $result->first(self::STARTED);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
