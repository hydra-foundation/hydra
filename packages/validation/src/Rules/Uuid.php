<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * The value must be a UUID in the canonical hyphenated form, of any version or
 * of the one named. The nil UUID is refused: it is the absence of an id.
 */
final class Uuid implements RuleInterface
{
    public function __construct(
        private readonly ?int $version = null,
        private readonly string $message = 'Enter a valid UUID.',
    ) {
        if ($version !== null && ($version < 1 || $version > 8)) {
            throw new InvalidArgumentException("UUID versions run from 1 to 8, not {$version}.");
        }
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_string($value)) {
            return $this->message;
        }

        $version = $this->version === null ? '[1-8]' : (string) $this->version;
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-' . $version . '[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        return preg_match($pattern, $value) === 1 ? null : $this->message;
    }
}
