<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Http\Contracts\EmitterInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The SAPI emitter: header() and echo, which is everything a PHP-FPM or CLI
 * server request needs and the reason this is the one class the kernel cannot
 * test end to end.
 */
final class Emitter implements EmitterInterface
{
    public function emit(ResponseInterface $response): void
    {
        // If output has already begun, the status line and headers are lost and
        // PHP would only emit "headers already sent" warnings. Fail loudly with
        // the culprit's location instead of corrupting the response.
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Cannot emit response: headers already sent in {$file}:{$line}.");
        }

        header(sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        ), true, $response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("{$name}: {$value}", false);
            }
        }

        echo $response->getBody();
    }
}
