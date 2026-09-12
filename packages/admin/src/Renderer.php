<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\ViewModels\ScreenViewModel;
use Hydra\Http\Htmx;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\View\Contracts\ViewInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * One screen, three depths: the whole page, the frame a sidebar click swaps, or
 * just the body a filter or a page link swaps. The htmx target picks the depth.
 */
final class Renderer
{
    public const FRAME = 'admin-frame';
    public const BODY = 'admin-body';

    public function __construct(
        private readonly Responder $respond,
        private readonly ViewInterface $view,
    ) {}

    /**
     * $toolbar renders above the swappable body rather than inside it, so a
     * filter input keeps focus across a swap.
     *
     * @param array<string, mixed> $data the body and toolbar templates' payload
     */
    public function screen(
        Request $request,
        ScreenViewModel $screen,
        string $body,
        array $data = [],
        ?string $toolbar = null,
        int|Status $status = Status::Ok,
    ): Response {
        $target = Htmx::fromRequest($request)->targetId();

        // The body renders inside this screen, so it is handed the screen —
        // at every depth, since a body template cannot tell which one it is
        // being rendered at. Applied last: it is structural, and a presenter
        // returning "screen" does not get to shadow it.
        $data = [...$data, 'screen' => $screen];

        if ($target === self::BODY) {
            return $this->respond->html($this->view->render($body, $data, layout: false), $status);
        }

        $bag = ['screen' => $screen, 'body' => $body, 'toolbar' => $toolbar, 'data' => $data];

        // The frame fragment also carries the new page title and an out-of-band
        // sidebar, because the sidebar sits outside the region being swapped.
        return $this->respond->html(
            $target === self::FRAME
                ? $this->view->render('admin/fragment', $bag, layout: false)
                : $this->view->render('admin/screen', $bag),
            $status,
        );
    }
}
