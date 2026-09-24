<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers an error in the format the request asked for: an HTML fragment for
 * htmx, RFC 9457 problem details for JSON, a page for a browser, and plain text
 * for anything that named neither, which is what curl and health checks send.
 *
 * htmx is checked first because it also sends Accept: text/html, and a full
 * page swapped into an element is the wrong answer to it.
 */
final class NegotiatingErrorRenderer implements ErrorRendererInterface
{
    /**
     * @param string|null $htmxTarget a selector for a region of the layout that
     *     htmx errors are sent to out of band; null swaps them where htmx would
     *     anyway
     */
    public function __construct(
        private readonly Responder $responder,
        private readonly ?string $htmxTarget = null,
    ) {}

    public function render(ErrorContext $context): ResponseInterface
    {
        $response = match (true) {
            Htmx::fromRequest($context->request)->isHtmx() => $this->fragment($context),
            default => match ($this->preferred($context->request->getHeaderLine('Accept'))) {
                'json' => $this->problem($context),
                'html' => $this->page($context),
                default => (new PlainTextErrorRenderer($this->responder))->render($context),
            },
        };

        // The same URL answers in several formats, so a cache has to key on
        // what chose between them.
        return $response->withHeader('Vary', 'Accept, HX-Request');
    }

    private function fragment(ErrorContext $context): ResponseInterface
    {
        $response = $this->responder->html($this->markup($context), $context->status);

        return $this->htmxTarget === null
            ? $response
            : $this->responder->htmx()->retarget($this->htmxTarget, 'innerHTML')->applyTo($response);
    }

    private function problem(ErrorContext $context): ResponseInterface
    {
        $title = Status::reasonFor($context->status) ?? 'Error';
        $problem = ['type' => 'about:blank', 'title' => $title, 'status' => $context->status];

        if ($context->clientMessage() !== $title) {
            $problem['detail'] = $context->clientMessage();
        }

        if ($context->debug) {
            $problem['exception'] = $context->error::class;
            $problem['message'] = $context->error->getMessage();
            $problem['file'] = $context->error->getFile();
            $problem['line'] = $context->error->getLine();
        }

        return $this->responder->json($problem, $context->status)
            ->withHeader('Content-Type', 'application/problem+json');
    }

    private function page(ErrorContext $context): ResponseInterface
    {
        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $context->status . ' ' . $this->e($context->clientMessage()) . '</title></head><body>'
            . $this->markup($context)
            . '</body></html>';

        return $this->responder->html($body, $context->status);
    }

    private function markup(ErrorContext $context): string
    {
        $markup = '<h1>' . $context->status . '</h1>'
            . '<p>' . $this->e($context->clientMessage()) . '</p>';

        if ($context->debug) {
            $error = $context->error;
            $markup .= '<pre>' . $this->e(sprintf(
                "%s: %s\nin %s:%d\n\n%s",
                $error::class,
                $error->getMessage(),
                $error->getFile(),
                $error->getLine(),
                $error->getTraceAsString(),
            )) . '</pre>';
        }

        return $markup;
    }

    /**
     * 'html', 'json' or null, by q-value, the earlier range winning a tie. Only
     * a named type counts: a browser lists text/html, while a bare wildcard is
     * what a client sends when it did not ask for anything.
     */
    private function preferred(string $accept): ?string
    {
        $best = null;
        $bestQ = 0.0;

        foreach (explode(',', $accept) as $range) {
            $params = explode(';', $range);
            $type = strtolower(trim(array_shift($params)));
            $q = 1.0;

            foreach ($params as $param) {
                [$name, $value] = array_pad(explode('=', $param, 2), 2, '');

                if (strtolower(trim($name)) === 'q') {
                    $q = (float) trim($value);
                }
            }

            $format = match (true) {
                $type === 'text/html', $type === 'application/xhtml+xml' => 'html',
                $type === 'application/json', str_ends_with($type, '+json') => 'json',
                default => null,
            };

            if ($format !== null && $q > $bestQ) {
                $best = $format;
                $bestQ = $q;
            }
        }

        return $best;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
