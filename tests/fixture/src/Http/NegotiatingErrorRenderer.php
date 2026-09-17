<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Http;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\ErrorContext;
use Hydra\Http\Htmx;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface;

/**
 * An error renderer that answers in the caller's own shape.
 *
 * The framework ships only {@see PlainTextErrorRenderer}, so this is the
 * application half of the seam — and the flows about a refusal being *rendered*
 * rather than thrown at the SAPI need an application that actually negotiates,
 * or they can only ever see one shape.
 */
final class NegotiatingErrorRenderer implements ErrorRendererInterface
{
    /** The htmx error fragment is swapped into this fixed region of the layout. */
    private const HTMX_ERROR_TARGET = '#app-error';

    public function __construct(
        private readonly Responder $responder,
        private readonly PlainTextErrorRenderer $text,
    ) {}

    public function render(ErrorContext $context): ResponseInterface
    {
        if (Htmx::fromRequest($context->request)->isHtmx()) {
            $response = $this->responder->html($this->markup($context), $context->status);

            return $this->responder->htmx()
                ->retarget(self::HTMX_ERROR_TARGET, 'innerHTML')
                ->applyTo($response);
        }

        $accept = $context->request->getHeaderLine('Accept');

        if (str_contains($accept, 'application/json')) {
            return $this->responder->json(
                ['error' => $context->clientMessage(), 'status' => $context->status],
                $context->status,
            );
        }

        if (str_contains($accept, 'text/html')) {
            return $this->responder->html(
                '<!doctype html><html lang="en"><head><meta charset="utf-8">'
                . '<title>' . $context->status . '</title></head><body>'
                . $this->markup($context)
                . '</body></html>',
                $context->status,
            );
        }

        // curl, health checks, anything that did not ask for html or json.
        return $this->text->render($context);
    }

    private function markup(ErrorContext $context): string
    {
        return '<h1>' . $this->e((string) $context->status) . '</h1>'
            . '<p>' . $this->e($context->clientMessage()) . '</p>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
