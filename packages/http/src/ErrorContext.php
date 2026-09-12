<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Http\Exceptions\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Everything an {@see Contracts\ErrorRendererInterface} needs to turn a caught
 * throwable into a response, in one value object: the error, the request that
 * triggered it, the resolved HTTP status, and whether debug detail is allowed.
 */
final readonly class ErrorContext
{
    public function __construct(
        public Throwable $error,
        public ServerRequestInterface $request,
        public int $status,
        public bool $debug,
    ) {}

    /**
     * The message safe to show a client, regardless of debug mode
     */
    public function clientMessage(): string
    {
        if ($this->error instanceof HttpException && $this->error->getMessage() !== '') {
            return $this->error->getMessage();
        }

        return Status::reasonFor($this->status) ?? 'Error';
    }
}
