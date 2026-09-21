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
            // Replace on a name's first value and append after it. The
            // response is the authority on a header it sets, including over
            // one PHP set on its own -- session_start()'s cache limiter is the
            // one that bites, since it makes every response uncacheable and no
            // amount of withHeader() can say otherwise. Appending from the
            // second value on is what keeps a name that legitimately repeats,
            // Set-Cookie above all, from losing all but its last.
            $replace = true;

            foreach ($values as $value) {
                header("{$name}: {$value}", $replace);
                $replace = false;
            }
        }

        echo $response->getBody();
    }
}
