<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Shared by every rule that asks whether a value was supplied, so "missing"
 * means the same thing to all of them. A file input left empty submits an
 * upload whose error says so, which is the file control's empty string.
 */
trait PresentValue
{
    private function isMissing(mixed $value): bool
    {
        return $value === null
            || $value === ''
            || (is_array($value) && $value === [])
            || ($value instanceof UploadedFileInterface && $value->getError() === UPLOAD_ERR_NO_FILE);
    }
}
