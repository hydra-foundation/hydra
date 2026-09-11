<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Plain text error
 */
final class PlainTextErrorRenderer implements ErrorRendererInterface
{
    public function __construct(private readonly Responder $responder) {}

    public function render(ErrorContext $context): ResponseInterface
    {
        return $this->responder->text($this->body($context), $context->status);
    }

    private function body(ErrorContext $context): string
    {
        if ($context->debug) {
            $error = $context->error;

            return sprintf(
                "%s: %s\nin %s:%d\n\n%s",
                $error::class,
                $error->getMessage(),
                $error->getFile(),
                $error->getLine(),
                $error->getTraceAsString(),
            );
        }

        return $context->clientMessage();
    }
}
