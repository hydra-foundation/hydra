<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use finfo;
use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The file is one of the allowed types, as read from its bytes. The type a
 * client declares is whatever the client wanted it to be, so it is never
 * consulted: a script uploaded as "avatar.png" with image/png is still a
 * script. A failed upload passes; its error is {@see UploadedFile}'s.
 */
final class MimeType implements RuleInterface
{
    /** How much of a file to sniff when it has no path: enough for any magic number. */
    private const SNIFF = 65536;

    private const WORDS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
        'image/gif' => 'GIF',
        'image/avif' => 'AVIF',
        'application/pdf' => 'PDF',
        'text/plain' => 'plain text',
        'text/csv' => 'CSV',
    ];

    /** @var list<string> */
    private readonly array $types;

    private ?string $message = null;

    public function __construct(string ...$types)
    {
        $this->types = array_values(array_map('strtolower', $types));
    }

    public function withMessage(string $message): self
    {
        $clone = clone $this;
        $clone->message = $message;

        return $clone;
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!$value instanceof UploadedFileInterface) {
            return $this->message();
        }

        if ($value->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        return in_array(self::detect($value), $this->types, true) ? null : $this->message();
    }

    /** The type the bytes say, or application/octet-stream when they say nothing. */
    public static function detect(UploadedFileInterface $upload): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $stream = $upload->getStream();
        $path = $stream->getMetadata('uri');

        if (is_string($path) && is_file($path)) {
            $type = $finfo->file($path);
        } else {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $type = $finfo->buffer($stream->read(self::SNIFF));
        }

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return is_string($type) && $type !== '' ? strtolower($type) : 'application/octet-stream';
    }

    private function message(): string
    {
        if ($this->message !== null) {
            return $this->message;
        }

        $words = array_map(static fn (string $type): string => self::WORDS[$type] ?? $type, $this->types);
        $images = array_filter($this->types, static fn (string $type): bool => str_starts_with($type, 'image/'));
        $noun = count($images) === count($this->types) ? 'image' : 'file';

        $last = array_pop($words);
        $listed = $words === [] ? $last : implode(', ', $words) . ' or ' . $last;

        return sprintf('The file must be a %s %s.', $listed, $noun);
    }
}
