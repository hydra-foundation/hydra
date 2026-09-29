<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
     * One page of a list as JSON: the items under `data`, the counts under
     * `meta`, and `links` to the first, previous, next and last pages, which
     * are also sent as an RFC 8288 `Link` header.
     *
     * The links are the request's own path and query string with only `page`
     * replaced, so filters and `per_page` carry over as the client sent them.
     * They are relative on purpose: an absolute link would have to trust the
     * Host header, and a forged one would be echoed back in every link.
     *
     * A page past the end is still a 200, with no items, and its `prev` leads
     * back to the last page.
     *
     * @param Paginated<mixed> $page
     */
    public function paginated(Paginated $page, ServerRequestInterface $request): ResponseInterface
    {
        $number = $page->paging->page;
        $last = $page->pages();
        $link = static function (int $to) use ($request): string {
            $query = $request->getQueryParams();
            unset($query['page']);
            $query['page'] = $to;

            return $request->getUri()->getPath() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        };

        $links = [
            'first' => $link(1),
            'prev' => $page->hasPrevious() ? $link(min($number - 1, $last)) : null,
            'next' => $page->hasNext() ? $link($number + 1) : null,
            'last' => $link($last),
        ];

        $header = [];

        foreach ($links as $rel => $url) {
            if ($url !== null) {
                $header[] = "<{$url}>; rel=\"{$rel}\"";
            }
        }

        return $this->json([
            'data' => $page->items,
            'meta' => [
                'page' => $number,
                'per_page' => $page->paging->perPage,
                'total' => $page->total,
                'pages' => $last,
            ],
            'links' => $links,
        ])->withHeader('Link', implode(', ', $header));
    }

    /**
     * A body the browser saves rather than renders, under $filename. How the
     * name is made safe for the header is {@see ContentDisposition}'s to say.
     */
    public function download(
        string $body,
        string $filename,
        string $contentType = 'application/octet-stream',
    ): ResponseInterface {
        return $this->make($body, Status::Ok, $contentType)
            ->withHeader('Content-Disposition', ContentDisposition::header(ContentDisposition::ATTACHMENT, $filename))
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
