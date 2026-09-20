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
     * One template, rendered on its own: a region that refreshes itself rather
     * than arriving as part of a screen. No layout, no chrome, no target to
     * read — what asked for it already knows where to put it.
     *
     * @param array<string, mixed> $data
     */
    public function fragment(string $template, array $data = [], int|Status $status = Status::Ok): Response
    {
        return $this->respond->html($this->view->render($template, $data, layout: false), $status);
    }

    /**
     * $toolbar renders above the swappable body rather than inside it, so a
     * filter input keeps focus across a swap.
     *
     * $oob is the price of that. A toolbar outside the swap is a toolbar that
     * does not re-render when the body does, so a control in it that depends on
     * the body's state is stale the moment somebody filters. These templates are
     * sent after the body on a body swap, out of band, to put such controls
     * back in step; at the other two depths the toolbar renders them inline and
     * this is not used, which is what keeps their ids unique.
     *
     * Plural because "toolbar things that depend on the body" is plural by
     * nature: a dashboard has both the line saying what it is answering for and
     * the totals strip, and one slot meant the second could not be kept current
     * without evicting the first.
     *
     * @param array<string, mixed> $data the body and toolbar templates' payload
     * @param list<string>|string|null $oob
     */
    public function screen(
        Request $request,
        ScreenViewModel $screen,
        string $body,
        array $data = [],
        ?string $toolbar = null,
        int|Status $status = Status::Ok,
        string|array|null $oob = null,
    ): Response {
        $target = Htmx::fromRequest($request)->targetId();

        // The body renders inside this screen, so it is handed the screen at
        // every depth, since a body template cannot tell which one it is being
        // rendered at. Applied last: it is structural, and a presenter returning
        // "screen" does not get to shadow it.
        $data = [...$data, 'screen' => $screen];

        if ($target === self::BODY) {
            $html = $this->view->render($body, $data, layout: false);

            foreach ((array) $oob as $template) {
                $html .= $this->view->render($template, [...$data, 'oob' => true], layout: false);
            }

            return $this->respond->html($html, $status);
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
