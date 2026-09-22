<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Http\Exceptions\BadRequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * JSON (any method) and urlencoded forms on PUT/PATCH/DELETE
 *
 * PHP fills $_POST for a POST only, so a form sent with any other method
 * arrives with its fields still in the raw body. PHP 8.4's request_parse_body()
 * does this natively, multipart included, which this does not attempt; the
 * urlencoded branch comes out once the floor is 8.4.
 */
final class ParseBodyMiddleware implements MiddlewareInterface
{
    private const IGNORED_METHODS = ['GET', 'HEAD'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getParsedBody() !== null
            || in_array($request->getMethod(), self::IGNORED_METHODS, true)) {
            return $handler->handle($request);
        }

        $body = $request->getBody();
        $raw = (string) $body;
        // Leave a re-readable stream for anything downstream that reads raw.
        // PSR-7 permits non-seekable streams, whose rewind() throws; for those,
        // downstream raw readers were never possible anyway.
        if ($body->isSeekable()) {
            $body->rewind();
        }

        if (trim($raw) === '') {
            return $handler->handle($request);
        }

        // The media type only; parameters like "; charset=utf-8" don't matter.
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));

        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            $decoded = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new BadRequestException('Malformed JSON request body.');
            }

            if (is_array($decoded)) {
                $request = $request->withParsedBody($decoded);
            }
        } elseif ($type === 'application/x-www-form-urlencoded') {
            parse_str($raw, $data);
            $request = $request->withParsedBody($data);
        }

        return $handler->handle($request);
    }
}
