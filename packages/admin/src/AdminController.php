<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\Events\Exported;
use Hydra\Admin\Events\RowCreated;
use Hydra\Admin\Events\RowDeleted;
use Hydra\Admin\Events\RowUpdated;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\Period;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ExportScreen;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\LinkCountsScreen;
use Hydra\Admin\Screens\PageScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Screens\WidgetScreen;
use Hydra\Admin\ViewModels\DashboardViewModel;
use Hydra\Admin\ViewModels\FormViewModel;
use Hydra\Admin\ViewModels\ListViewModel;
use Hydra\Admin\ViewModels\ShowViewModel;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Core\Clock\SystemClock;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\Htmx;
use Hydra\Http\ParsedBody;
use Hydra\Http\Query;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Validation\Validator;
use Generator;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The shared handler behind the generated screen routes. It resolves the module
 * and screen from the path, enforces the screen's ability, and renders.
 */
final class AdminController
{
    /**
     * How long a browser may answer its own counts request from cache.
     *
     * Long enough to cover a spell of clicking around one list, short enough
     * that a tally another session moved is wrong for less time than it takes
     * to notice. A write made in *this* session does not wait it out.
     */
    private const COUNTS_MAX_AGE = 10;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Chrome $chrome,
        private readonly Renderer $renderer,
        private readonly GateInterface $gate,
        private readonly Responder $respond,
        private readonly Validator $validator,
        /**
         * OPTIONAL, and last for a reason: the admin depends on the PSR
         * interface and not on hydrakit/event, so an application that has
         * bound no dispatcher gets null here and the admin simply announces
         * nothing. {@see AdminServiceProvider} has to hand this over by name,
         * because container autowiring passes an optional parameter by.
         */
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ClockInterface $clock = new SystemClock,
        /**
         * OPTIONAL, and last, for the same reason as the dispatcher above: an
         * application that binds none reads its rows in the zone they are
         * stored in, which is what it saw before there was a setting at all.
         */
        private readonly TimezoneInterface $timezone = new FixedTimezone,
    ) {}

    public function list(Request $request): Response
    {
        [$blueprint] = $this->resolve($request);
        $criteria = Criteria::fromQuery(Query::fromRequest($request), $blueprint);

        return $this->table($request, $blueprint, $this->registry->source($blueprint)->page($criteria));
    }

    /**
     * How many rows sit behind each of the module's filter links, as the bar
     * that asked for them, ready to render in their place.
     *
     * A tally per link is a query per link, which is why it is a request of its
     * own and arrives after the table: the list is what the visitor is waiting
     * for, and a slow count should hold nothing up. Each one is counted the way
     * clicking that link would be read, so the number beside a link and the
     * table it leads to can never disagree.
     */
    public function counts(Request $request): Response
    {
        [$blueprint, $screen] = $this->resolve($request);

        if (!$screen instanceof LinkCountsScreen) {
            throw new NotFoundException;
        }

        $query = Query::fromRequest($request);
        $source = $this->registry->source($blueprint);
        $counts = [];

        foreach ($blueprint->links as $link) {
            // The smallest page there is, rather than none: page() is the whole
            // of what a source promises, and the total rides along with it.
            $counts[$link->key()] = $source->page(
                Criteria::fromQuery($query, $blueprint, $link)->inPagesOf(1),
            )->total;
        }

        return $this->renderer->fragment('admin/partials/links', [
            // No rows are read for this and none are rendered: the bar asks the
            // view model only which link is current and where the others lead,
            // both of which are the criteria's to answer.
            'vm' => new ListViewModel(
                $blueprint,
                new Page([], 0, Criteria::fromQuery($query, $blueprint)),
                $this->registry->prefix(),
            ),
            'counts' => $counts,
        ])
            // The bar is re-rendered empty by every body swap and asks for these
            // again each time, though clicking a link or a column heading cannot
            // change a single one of them. A few seconds of browser cache turns
            // that back into one request per thing that actually moves the
            // numbers. Private, and varying on the cookie, because a tally is
            // one account's view of the table and not the next visitor's; and
            // short, because this is the only thing standing between a write and
            // a stale number — see done(), which spends a token to skip it.
            ->withHeader('Cache-Control', 'private, max-age=' . self::COUNTS_MAX_AGE)
            ->withHeader('Vary', 'Cookie');
    }

    /**
     * The list as a file. The query string is read the same way the list reads
     * it, so what downloads is the view the visitor is looking at rather than
     * the table behind it; the page number is the one thing it drops, since an
     * export is of the whole view and not of the place in it they had got to.
     *
     * The file is built in memory and sent whole. A stream would hold less of
     * it at once, but a response that has already started cannot become the
     * error page it turns out to need, and an admin export bounded by
     * {@see ExportScreen::limit()} is small enough that the trade is the wrong
     * way round.
     */
    public function export(Request $request): Response
    {
        [$blueprint, $screen] = $this->resolve($request);

        if (!$screen instanceof ExportScreen) {
            throw new NotFoundException;
        }

        $criteria = Criteria::fromQuery(Query::fromRequest($request), $blueprint);
        $exported = 0;

        $csv = Csv::render(
            $blueprint->fieldsOn(Surface::Export),
            $this->counting($this->registry->extractor($blueprint)->rows($criteria, $screen->rowLimit()), $exported),
        );

        $this->events?->dispatch(new Exported($blueprint->slug, $criteria, $exported));

        return $this->respond->download(
            $csv,
            $screen->filename($blueprint->slug, $this->clock->now()),
            'text/csv; charset=utf-8',
        );
    }

    /**
     * The same rows, leaving a running count behind in $count.
     *
     * How many rows went out is the most useful thing an export's audit line
     * carries and the one thing streaming them past the writer throws away, so
     * it is taken on the way through rather than by measuring the file
     * afterwards.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return Generator<int, array<string, mixed>>
     */
    private function counting(iterable $rows, int &$count): Generator
    {
        $count = 0;

        foreach ($rows as $row) {
            ++$count;

            yield $row;
        }
    }

    /**
     * A grid of widgets, and none of their contents. Every card comes back as a
     * placeholder that fetches its own body, so the page is done as soon as the
     * layout is: no query on this dashboard runs before the visitor sees it.
     */
    public function dashboard(Request $request): Response
    {
        [$blueprint, $screen] = $this->resolve($request);

        if (!$screen instanceof DashboardScreen) {
            throw new NotFoundException;
        }

        $heading = $screen->heading();

        return $this->renderer->screen(
            $request,
            trim($screen->path(), '/') === ''
                ? $this->chrome->module($blueprint, $heading)
                : $this->chrome->screen($blueprint, $heading ?? $blueprint->title),
            $screen->template(),
            ['vm' => new DashboardViewModel(
                $blueprint,
                $screen,
                $this->registry->prefix(),
                array_values(array_filter(
                    $screen->cards(),
                    fn (Widget $card): bool => $card->ability() === null || $this->gate->allows($card->ability()),
                )),
                $this->period($request),
                $this->visible($screen->summary()),
            )],
            // The strip and the period control render above the body rather
            // than inside it. The control targets the body, and a select that
            // sits in what it replaces loses focus to its own answer; the strip
            // answers for all of time, so being outside the swap is how it
            // survives a period change without being told to.
            toolbar: 'admin/partials/dashboard-toolbar',
            // Which leaves one thing in the toolbar that a body swap makes
            // stale: the line saying what the cards are answering for.
            oob: 'admin/partials/period-status',
        );
    }

    /**
     * One card, filled. A widget's own ability is checked here and not only
     * where the grid drew it, because the URL is reachable on its own and a
     * card left off a dashboard is not a card withheld.
     */
    public function widget(Request $request): Response
    {
        [$blueprint, $screen] = $this->resolve($request);
        $key = $request->getAttribute('widget');

        if (!$screen instanceof WidgetScreen || !is_string($key)) {
            throw new NotFoundException;
        }

        $dashboard = $blueprint->screen($screen->dashboard());
        $widget = $dashboard instanceof DashboardScreen ? $dashboard->card($key) : null;

        if ($widget === null) {
            throw new NotFoundException;
        }

        if ($widget->ability() !== null) {
            $this->gate->authorize($widget->ability());
        }

        $period = $this->period($request);

        // The strip above the grid is a card with different chrome and a
        // standing instruction to survive a period change, so it comes back
        // drawn as itself rather than as one of the cards below.
        $partial = $dashboard->summary()?->key() === $key
            ? 'admin/partials/summary-body'
            : 'admin/partials/widget';

        return $this->renderer->fragment($partial, [
            'widget' => $widget,
            'url' => (new DashboardViewModel(
                $blueprint,
                $dashboard,
                $this->registry->prefix(),
                period: $period,
            ))->url($widget),
            'data' => $this->registry->presentWidget(
                $widget,
                $period->window($this->clock, $this->timezone->zone()),
            ),
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The stretch of time the request is asking about. Read off the URL and
     * nowhere else, so that a card's address is the whole question and the
     * refresh beside it can simply ask again.
     */
    private function period(Request $request): Period
    {
        $period = $request->getQueryParams()['period'] ?? null;

        return Period::fromKey(is_string($period) ? $period : null);
    }

    /** The summary strip, unless this visitor is not allowed it. */
    private function visible(?Widget $summary): ?Widget
    {
        if ($summary === null || $summary->ability() === null) {
            return $summary;
        }

        return $this->gate->allows($summary->ability()) ? $summary : null;
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

        $this->events?->dispatch(new RowCreated($blueprint->slug, $id, $result->validated()));

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
        // Read rather than merely checked for: once the write lands, what the
        // row used to say is gone, and that is the half of an audit trail worth
        // having. The lookup was already being paid for.
        $before = $source->find($id);

        if ($before === null) {
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

        $this->events?->dispatch(new RowUpdated($blueprint->slug, $id, $result->validated(), $before));

        $saved = $source->find($id) ?? $submitted;

        if (ParsedBody::fromRequest($request)->string('_action') === 'apply') {
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

        $source = $this->registry->deleteSource($blueprint);
        // Read before the write lands, the way update() does, and for a stronger
        // reason: an updated row can still be read afterwards and a deleted one
        // cannot. Skipped when nothing is listening, so a module with no audit
        // trail does not pay for a lookup on every delete.
        $before = $this->events !== null && $source instanceof RowSourceInterface
            ? $source->find($id) ?? []
            : [];

        try {
            $source->delete($id);
        } catch (WriteRejected $rejected) {
            return $this->done(
                $request,
                $blueprint,
                Notice::failure($rejected->summary()),
                Status::UnprocessableEntity,
            );
        }

        $this->events?->dispatch(new RowDeleted($blueprint->slug, $id, $before));

        return $this->done($request, $blueprint, Notice::deleted());
    }

    /**
     * Where a new row leaves the visitor: on the row itself, which is what the id
     * {@see \Hydra\Admin\Contracts\CreateSourceInterface::create()} returns is
     * for. A module with no screen for one row has nowhere to open, and comes back
     * to the list like any other write, as does a row the source cannot find
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
            ['vm' => new ShowViewModel($blueprint, $id, $this->registry->prefix(), $row, $this->timezone->zone())],
        );
    }

    /**
     * Back to the list once a write is finished: the same view of it the write
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
            ->applyTo($this->table(
                $request,
                $blueprint,
                $page,
                $notice,
                $status,
                // The row that just changed is very likely one of the rows a
                // tally counts, and the browser is holding an answer from
                // before it changed. This is the one moment the cache must not
                // be allowed to speak, so the bar is sent to an address it has
                // never seen.
                countsToken: $this->clock->now()->format('Uu'),
            ));
    }

    /**
     * The list the write was made from. htmx reports the page the browser is on,
     * which is where the criteria live; a write sent without it (a form posted
     * with no htmx) has only its own URL to go on, and lands on the defaults.
     */
    private function listState(Request $request, Blueprint $blueprint): Criteria
    {
        $current = Htmx::fromRequest($request)->currentUrl();

        return $current === null
            ? Criteria::defaults($blueprint)
            : Criteria::fromQuery(Query::fromUrl($current), $blueprint);
    }

    /**
     * The rows for this view of the list. A delete can empty the page it was on
     * (the last row of the last page), and an empty page is not what the visitor
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
        ?string $countsToken = null,
    ): Response {
        return $this->renderer->screen(
            $request,
            $this->chrome->module($blueprint, notice: $notice),
            'admin/partials/table',
            ['vm' => new ListViewModel(
                $blueprint,
                $page,
                $this->registry->prefix(),
                $countsToken,
                $this->timezone->zone(),
            )],
            toolbar: 'admin/partials/filters',
            status: $status,
            // The export link lives in the toolbar and depends on the criteria
            // the body was just rendered for, so a body swap has to carry a
            // fresh one with it.
            oob: 'admin/partials/export',
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
        $input = ParsedBody::fromRequest($request);
        $values = [];

        foreach ($screen->controls() as $control) {
            if (!$control->isReadonly()) {
                $values[$control->name()] = trim($input->string($control->name()));
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
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

    /**
     * A screen that names one row, and the id its path carried.
     *
     * @return array{0: Blueprint, 1: ScreenInterface, 2: string}
     */
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
