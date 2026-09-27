<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** An upload whose connection drops after the first read, the way a client can. */
final class FailingStream implements StreamInterface
{
    private readonly StreamInterface $inner;
    private int $reads = 0;

    public function __construct(string $bytes)
    {
        $this->inner = Stream::create($bytes);
    }

    public function read(int $length): string
    {
        if (++$this->reads > 1) {
            throw new RuntimeException('The connection dropped.');
        }

        return $this->inner->read($length);
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }
}
