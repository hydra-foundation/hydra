<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * The value must not be one of an explicit set, compared strictly on the
 * string form the way {@see InList} compares. Reserved usernames, blocked
 * domains, a status a form may not set directly.
 */
final class NotInList implements RuleInterface
{
    use TextualValue;

    /** @var list<string> */
    private readonly array $denied;

    private readonly string $message;

    /** @param list<string|int> $denied */
    public function __construct(
        array $denied,
        ?string $message = null,
        private readonly bool $caseSensitive = true,
    ) {
        if ($denied === []) {
            throw new InvalidArgumentException('NotInList needs at least one denied value.');
        }

        $this->denied = array_values(array_map(
            fn (string|int $value): string => $caseSensitive ? (string) $value : strtolower((string) $value),
            $denied,
        ));
        $this->message = $message ?? 'That value is not available.';
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!$this->isTextual($value)) {
            return null;
        }

        $subject = (string) $value;

        return in_array($this->caseSensitive ? $subject : strtolower($subject), $this->denied, true)
            ? $this->message
            : null;
    }
}
