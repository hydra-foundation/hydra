<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The file is no larger than the limit, measured from the stream that holds
 * it rather than any size the request declared. A failed upload passes: its
 * error is {@see UploadedFile}'s to report.
 */
final class MaxFileSize implements RuleInterface
{
    private readonly string $message;

    public function __construct(private readonly int $bytes, ?string $message = null)
    {
        $this->message = $message ?? sprintf('The file must be %s or smaller.', self::readable($bytes));
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!$value instanceof UploadedFileInterface) {
            return $this->message;
        }

        if ($value->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        $size = $value->getStream()->getSize() ?? $value->getSize() ?? PHP_INT_MAX;

        return $size > $this->bytes ? $this->message : null;
    }

    /** "2 MB", "512 KB", "1.5 MB": the unit a person would use, in binary multiples as upload limits are. */
    private static function readable(int $bytes): string
    {
        foreach (['MB' => 1024 * 1024, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return rtrim(rtrim(number_format($bytes / $size, 1, '.', ''), '0'), '.') . ' ' . $unit;
            }
        }

        return $bytes . ' bytes';
    }
}
