<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds common PSR-7 responses from the PSR-17 factories
 */
final class Responder
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        /**
         * OPTIONAL, and last, so an application with no policy in front of it
         * builds a Responder the way it always did. Without one the directive
         * elements below carry no nonce, which is correct: there is nothing for
         * them to be vouched against.
         */
        private readonly ?CspNonce $nonce = null,
    ) {}

    public function text(string $body, int|Status $status = Status::Ok): ResponseInterface
    {
        return $this->make($body, $status, 'text/plain; charset=utf-8');
    }

    public function html(string $body, int|Status $status = Status::Ok): ResponseInterface
    {
        return $this->make($body, $status, 'text/html; charset=utf-8');
    }

    public function json(mixed $data, int|Status $status = Status::Ok): ResponseInterface
    {
        $body = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $this->make($body, $status, 'application/json');
    }

    /**
     * A body the browser saves rather than renders.
     *
     * Both spellings of the name are sent. The quoted one is what every client
     * has always read, and it carries only characters a header can hold
     * literally: a name is attacker-influenced often enough (a row's title, a
     * module's slug) that the quote and the newline inside it are worth
     * spending a transliteration on. filename* is RFC 5987 and carries the name
     * as written for the clients that prefer it, which is every current one.
     */
    public function download(
        string $body,
        string $filename,
        string $contentType = 'application/octet-stream',
    ): ResponseInterface {
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename), '_');

        return $this->make($body, Status::Ok, $contentType)
            ->withHeader('Content-Disposition', sprintf(
                'attachment; filename="%s"; filename*=UTF-8\'\'%s',
                $ascii === '' ? 'download' : $ascii,
                rawurlencode($filename),
            ))
            ->withHeader('Content-Length', (string) strlen($body));
    }

    public function noContent(int|Status $status = Status::NoContent): ResponseInterface
    {
        return $this->responses->createResponse(Status::toInt($status));
    }

    public function redirect(string $location, int|Status $status = Status::Found): ResponseInterface
    {
        return $this->responses->createResponse(Status::toInt($status))->withHeader('Location', $location);
    }

    /**
     * Directives for an htmx client. They are written into the body rather than
     * the headers, which is why they need the stream factory this holds.
     */
    public function htmx(): HtmxResponse
    {
        return new HtmxResponse($this->streams, $this->nonce?->value());
    }

    private function make(string $body, int|Status $status, string $contentType): ResponseInterface
    {
        return $this->responses->createResponse(Status::toInt($status))
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streams->createStream($body));
    }
}
