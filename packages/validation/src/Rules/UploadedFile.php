<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The value is a file that arrived whole. PHP reports a failed upload as an
 * error code on an otherwise ordinary-looking value, so without this rule a
 * size or type check would be the first to notice, and would say the wrong
 * thing about it.
 *
 * Put {@see Nullable} ahead of it on an optional file: "no file chosen" is
 * then nothing to check rather than an error.
 */
final class UploadedFile implements RuleInterface
{
    public function __construct(private readonly ?string $message = null) {}

    public function validate(mixed $value, Context $context): ?string
    {
        if (!$value instanceof UploadedFileInterface) {
            return $this->message ?? 'Choose a file to upload.';
        }

        if ($value->getError() === UPLOAD_ERR_OK) {
            return null;
        }

        return $this->message ?? match ($value->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server accepts.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            default => 'The server could not save the upload.',
        };
    }
}
