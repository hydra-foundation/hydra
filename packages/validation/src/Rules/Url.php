<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * The value must be a URL on one of the allowed schemes. The allowlist is not
 * optional decoration: PHP's URL filter accepts `javascript:` and `data:`, and
 * a stored link is rendered into an href later.
 */
final class Url implements RuleInterface
{
    /** @var list<string> */
    private readonly array $schemes;

    /** @param list<string> $schemes */
    public function __construct(
        array $schemes = ['http', 'https'],
        private readonly string $message = 'Enter a valid URL.',
    ) {
        if ($schemes === []) {
            throw new InvalidArgumentException('Url needs at least one allowed scheme.');
        }

        $this->schemes = array_values(array_map(strtolower(...), $schemes));
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return $this->message;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), $this->schemes, true)
            ? null
            : $this->message;
    }
}
