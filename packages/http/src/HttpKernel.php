<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Core\Contracts\KernelInterface;
use Hydra\Http\Contracts\EmitterInterface;
use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * HTTP Kernel
 *
 * Drives the HTTP request lifecycle
 */
final class HttpKernel implements KernelInterface
{
    public function __construct(
        private readonly ServerRequestProviderInterface $requests,
        private readonly RequestHandlerInterface $handler,
        private readonly EmitterInterface $emitter,
    ) {}

    public function handle(): void
    {
        try {
            $this->emitter->emit(
                $this->handler->handle($this->requests->fromGlobals())
            );
        } catch (Throwable $e) {
            $this->panic($e);
        }
    }

    /**
     * Emit a minimal plain-text 500 for a throwable nothing else caught
     */
    private function panic(Throwable $e): void
    {
        error_log(sprintf(
            'Uncaught %s outside the error boundary: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo 'Internal Server Error';
    }

    public function terminate(): void {}
}
