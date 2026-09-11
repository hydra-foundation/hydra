<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\PageScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\ViewModels\FormViewModel;
use Hydra\Admin\ViewModels\ListViewModel;
use Hydra\Admin\ViewModels\ShowViewModel;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\Htmx;
use Hydra\Http\Input as SubmittedInput;
use Hydra\Http\Query;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin controller
 *
 * The shared handler behind the generated screen routes. It resolves the module
 * and screen from the path, enforces the screen's ability, and renders.
 */
final class AdminController
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Chrome $chrome,
        private readonly Renderer $renderer,
        private readonly GateInterface $gate,
        private readonly Responder $respond,
        private readonly Validator $validator,
    ) {}

    public function list(Request $request): Response
    {
        [$blueprint] = $this->resolve($request);
        $criteria = Criteria::fromQuery(Query::fromRequest($request), $blueprint);

        return $this->table($request, $blueprint, $this->registry->source($blueprint)->page($criteria));
    }

    public function page(Request $request): Response
    {
        [$blueprint, $screen] = $this->resolve($request);

        if (!$screen instanceof PageScreen) {
            throw new NotFoundException;
        }

        $heading = $screen->heading();

        return $this->renderer->screen(
            $request,
            trim($screen->path(), '/') === ''
                ? $this->chrome->module($blueprint, $heading)
                : $this->chrome->screen($blueprint, $heading ?? $blueprint->title),
            $screen->template(),
            $this->registry->present($screen),
        );
    }

    public function show(Request $request): Response
    {
        [$blueprint, $screen, $id] = $this->resolveRow($request);

        if (!$screen instanceof ShowScreen) {
            throw new NotFoundException;
        }

        $row = $this->registry->rowSource($blueprint)->find($id);

        if ($row === null) {
            throw new NotFoundException;
        }

        return $this->row($request, $blueprint, $screen, $id, $row);
    }

    public function create(Request $request): Response
    {
        [$blueprint, $screen] = $this->resolve($request);

        if (!$screen instanceof FormScreen) {
            throw new NotFoundException;
        }

        return $this->form($request, $blueprint, $screen, null, []);
    }

    public function store(Request $request): Response
    {
        [$blueprint, $screen] = $this->resolve($request);

        if (!$screen instanceof FormScreen) {
            throw new NotFoundException;
        }

        $submitted = $this->submitted($request, $screen);
        $result = $this->validator->validate($submitted, $screen->rulesFor($submitted));

        if ($result->fails()) {
            return $this->form($request, $blueprint, $screen, null, $submitted, $result->errors(), Status::UnprocessableEntity);
        }

        try {
            $id = $this->registry->createSource($blueprint)->create($result->validated());
        } catch (WriteRejected $rejected) {
            return $this->form($request, $blueprint, $screen, null, $submitted, $rejected->errors(), Status::UnprocessableEntity);
        }

        return $this->written($request, $blueprint, $id);
    }

    public function edit(Request $request): Response
    {
        [$blueprint, $screen, $id] = $this->resolveForm($request);
        $row = $this->registry->updateSource($blueprint)->find($id);

        if ($row === null) {
            throw new NotFoundException;
        }

        return $this->form($request, $blueprint, $screen, $id, $row);
    }

    public function update(Request $request): Response
    {
        [$blueprint, $screen, $id] = $this->resolveForm($request);
        $source = $this->registry->updateSource($blueprint);

        if ($source->find($id) === null) {
            throw new NotFoundException;
        }

        $submitted = $this->submitted($request, $screen);
        $result = $this->validator->validate($submitted, $screen->rulesFor($submitted));

        if ($result->fails()) {
            return $this->form($request, $blueprint, $screen, $id, $submitted, $result->errors(), Status::UnprocessableEntity);
        }

        try {
            $source->update($id, $result->validated());
        } catch (WriteRejected $rejected) {
            return $this->form($request, $blueprint, $screen, $id, $submitted, $rejected->errors(), Status::UnprocessableEntity);
        }

        $saved = $source->find($id) ?? $submitted;

        if (SubmittedInput::fromRequest($request)->string('_action') === 'apply') {
            return $this->form($request, $blueprint, $screen, $id, $saved, notice: Notice::saved());
        }

        return $this->done($request, $blueprint, Notice::saved());
    }

    /**
     * The row goes, and the list it came from comes back. Nothing is confirmed
     * here: a POST is the confirmation, and refusing one is the source's right.
     */
    public function destroy(Request $request): Response
    {
        [$blueprint, $screen, $id] = $this->resolveRow($request);

        if (!$screen instanceof DeleteScreen) {
            throw new NotFoundException;
        }

        try {
            $this->registry->deleteSource($blueprint)->delete($id);
        } catch (WriteRejected $rejected) {
            return $this->done(
                $request,
                $blueprint,
                Notice::failure($rejected->summary()),
                Status::UnprocessableEntity,
            );
        }

        return $this->done($request, $blueprint, Notice::deleted());
    }

    /**
     * Where a new row leaves the visitor: on the row itself, which is what the id
     * {@see \Hydra\Admin\Contracts\CreateSourceInterface::create()} returns is
     * for. A module with no screen for one row has nowhere to open, and comes back
     * to the list like any other write — as does a row the source cannot find
     * again, since the write did happen and the list is what can still be shown.
     */
    private function written(Request $request, Blueprint $blueprint, string $id): Response
    {
        $notice = Notice::created();
        $screen = $blueprint->screen('show');
        $url = $this->registry->rowUrl($blueprint, 'show', $id);
        $row = $screen instanceof ShowScreen ? $this->registry->rowSource($blueprint)->find($id) : null;

        if (!$screen instanceof ShowScreen || $url === null || $row === null) {
            return $this->done($request, $blueprint, $notice);
        }

        if (!Htmx::fromRequest($request)->isHtmx()) {
            return $this->respond->redirect($url);
        }

        return $this->respond->htmx()
            ->pushUrl($url)
            ->applyTo($this->row($request, $blueprint, $screen, $id, $row, $notice));
    }

    /** @param array<string, mixed> $row */
    private function row(
        Request $request,
        Blueprint $blueprint,
        ShowScreen $screen,
        string $id,
        array $row,
        ?Notice $notice = null,
    ): Response {
        return $this->renderer->screen(
            $request,
            $this->chrome->screen($blueprint, $screen->heading() ?? $blueprint->title, $id, $notice),
            'admin/partials/show',
            ['vm' => new ShowViewModel($blueprint, $id, $this->registry->prefix(), $row)],
        );
    }

    /**
     * Back to the list once a write is finished — the same view of it the write
     * was made from, not the first page of an unfiltered table. A plain redirect
     * would be turned into a client-side navigation and reload the whole page, so
     * an htmx client is handed the list it was going to fetch anyway, with the URL
     * pushed after it. A refusal is rendered rather than redirected, because a redirect
     * would throw away the only account of why the row is still there.
     */
    private function done(
        Request $request,
        Blueprint $blueprint,
        ?Notice $notice = null,
        Status $status = Status::Ok,
    ): Response {
        $criteria = $this->listState($request, $blueprint);

        if (!Htmx::fromRequest($request)->isHtmx() && $status === Status::Ok) {
            return $this->respond->redirect($this->listUrl($blueprint, $criteria));
        }

        $page = $this->rows($blueprint, $criteria);

        return $this->respond->htmx()
            ->pushUrl($this->listUrl($blueprint, $page->criteria))
            ->applyTo($this->table($request, $blueprint, $page, $notice, $status));
    }

    /**
     * The list the write was made from. htmx reports the page the browser is on,
     * which is where the criteria live; a write sent without it — a form posted
     * with no htmx — has only its own URL to go on, and lands on the defaults.
     */
    private function listState(Request $request, Blueprint $blueprint): Criteria
    {
        $current = Htmx::fromRequest($request)->currentUrl();

        return $current === null
            ? Criteria::defaults($blueprint)
            : Criteria::fromQuery(Query::fromUrl($current), $blueprint);
    }

    /**
     * The rows for this view of the list. A delete can empty the page it was on —
     * the last row of the last page — and an empty page is not what the visitor
     * asked to be shown, so the list falls back to its new end.
     */
    private function rows(Blueprint $blueprint, Criteria $criteria): Page
    {
        $source = $this->registry->source($blueprint);
        $page = $source->page($criteria);

        if ($page->isEmpty() && $criteria->page > 1) {
            return $source->page($criteria->onPage($page->pages()));
        }

        return $page;
    }

    /**
     * Where this view of the list lives. The default view is what the bare module
     * URL already shows, so it is not spelled out again in the query string.
     */
    private function listUrl(Blueprint $blueprint, Criteria $criteria): string
    {
        $root = $this->registry->root($blueprint);
        $query = $criteria->toQuery();

        return $query === Criteria::defaults($blueprint)->toQuery()
            ? $root
            : $root . '?' . http_build_query($query);
    }

    private function table(
        Request $request,
        Blueprint $blueprint,
        Page $page,
        ?Notice $notice = null,
        int|Status $status = Status::Ok,
    ): Response {
        return $this->renderer->screen(
            $request,
            $this->chrome->module($blueprint, notice: $notice),
            'admin/partials/table',
            ['vm' => new ListViewModel($blueprint, $page, $this->registry->prefix())],
            toolbar: 'admin/partials/filters',
            status: $status,
        );
    }

    /**
     * Only the declared controls are read off the request, so a hand-crafted
     * POST cannot introduce a column the screen never offered.
     *
     * @return array<string, string>
     */
    private function submitted(Request $request, FormScreen $screen): array
    {
        $input = SubmittedInput::fromRequest($request);
        $values = [];

        foreach ($screen->controls() as $control) {
            if (!$control->isReadonly()) {
                $values[$control->name()] = trim($input->string($control->name()));
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function form(
        Request $request,
        Blueprint $blueprint,
        FormScreen $screen,
        ?string $id,
        array $values,
        array $errors = [],
        int|Status $status = Status::Ok,
        ?Notice $notice = null,
    ): Response {
        return $this->renderer->screen(
            $request,
            $this->chrome->screen(
                $blueprint,
                $screen->heading() ?? $blueprint->title,
                $id === null ? 'New' : 'Edit ' . $id,
                $notice,
            ),
            'admin/partials/form',
            ['vm' => new FormViewModel($blueprint, $screen, $id, $this->registry->prefix(), $values, $errors)],
            status: $status,
        );
    }

    /** @return array{0: Blueprint, 1: FormScreen, 2: string} */
    private function resolveForm(Request $request): array
    {
        [$blueprint, $screen, $id] = $this->resolveRow($request);

        if (!$screen instanceof FormScreen) {
            throw new NotFoundException;
        }

        return [$blueprint, $screen, $id];
    }

    /** A screen that names one row, and the id its path carried. @return array{0: Blueprint, 1: ScreenInterface, 2: string} */
    private function resolveRow(Request $request): array
    {
        [$blueprint, $screen] = $this->resolve($request);
        $id = $request->getAttribute('id');

        if (!is_string($id) || $id === '') {
            throw new NotFoundException;
        }

        return [$blueprint, $screen, $id];
    }

    /** @return array{0: Blueprint, 1: ScreenInterface} */
    private function resolve(Request $request): array
    {
        $path = $request->getUri()->getPath();
        $blueprint = $this->registry->fromPath($path);
        $screen = $blueprint === null
            ? null
            : $this->registry->screenAt($blueprint, $path, $request->getMethod());

        if ($blueprint === null || $screen === null) {
            throw new NotFoundException;
        }

        $ability = $screen->ability() ?? $blueprint->ability;

        if ($ability !== null) {
            $this->gate->authorize($ability);
        }

        return [$blueprint, $screen];
    }
}
