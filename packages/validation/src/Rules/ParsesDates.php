<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use DateTimeImmutable;

/**
 * Shared by the date rules. The leading `!` zeroes every field the format does
 * not mention, so a 'Y-m-d' value lands at midnight instead of picking up the
 * current time and making two equal dates compare unequal.
 */
trait ParsesDates
{
    private function parse(mixed $value, string $format): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);

        if ($parsed === false) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $parsed;
    }
}
