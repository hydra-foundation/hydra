<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;
use Stringable;

/**
 * The value must be one of an explicit set.
 *
 * The comparison is strict on the string form, so "1" never matches 1 and a
 * value cannot slip in through PHP's looser comparisons. A rule that decides
 * which column a query may sort by has to mean exactly what it lists.
 */
final class InList implements RuleInterface
{
    /** @var list<string> */
    private readonly array $allowed;

    private readonly string $message;

    /** @param list<string|int> $allowed */
    public function __construct(array $allowed, ?string $message = null)
    {
        if ($allowed === []) {
            // An empty set rejects everything, which is almost always a caller
            // passing through a list it meant to populate.
            throw new InvalidArgumentException('InList needs at least one allowed value.');
        }

        $this->allowed = array_values(array_map(strval(...), $allowed));
        $this->message = $message ?? 'Must be one of: ' . implode(', ', $this->allowed) . '.';
    }

    public function validate(mixed $value): ?string
    {
        if (!$this->isTextual($value)) {
            return $this->message;
        }

        return in_array((string) $value, $this->allowed, true) ? null : $this->message;
    }

    /**
     * Whether the value can be safely treated as text. Booleans are excluded
     * deliberately: a bool casts to "1"/"" and would match an unrelated entry
     * rather than the value the client actually sent.
     */
    private function isTextual(mixed $value): bool
    {
        return $value === null
            || is_string($value)
            || is_int($value)
            || is_float($value)
            || $value instanceof Stringable;
    }
}
