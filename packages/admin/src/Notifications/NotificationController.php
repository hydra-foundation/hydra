<?php

declare(strict_types=1);

namespace Hydra\Admin\Notifications;

use Hydra\Admin\Live\LiveAdmin;
use Hydra\Admin\Renderer;
use Hydra\Admin\Widgets\Readable;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\Responder;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The bell's four requests: the badge, the list, reading one, reading all.
 * Each answers for the signed-in user and nobody else; a notice of anyone
 * else's is not found rather than refused, so its id says nothing.
 */
final readonly class NotificationController
{
    /** What the dropdown shows: the latest, read or not. */
    public const SHOWN = 10;

    private const PLACES = ['sidebar', 'topbar'];

    public function __construct(
        private NotificationStoreInterface $store,
        private Notifier $notifier,
        private GuardInterface $guard,
        private Renderer $renderer,
        private Responder $respond,
        private ClockInterface $clock,
        private LiveAdmin $live,
        private string $prefix,
    ) {}

    /** The unread count beside the bell; the one part that refetches itself. */
    public function badge(Request $request): Response
    {
        $user = $this->user();
        $place = $request->getQueryParams()['place'] ?? null;
        $place = in_array($place, self::PLACES, true) ? $place : self::PLACES[0];

        return $this->fragment('admin/partials/bell-badge', [
            'place' => $place,
            'count' => $this->store->unreadCount($user),
            'url' => $this->url('/notifications/badge') . '?place=' . $place,
            'topic' => $this->live->enabled ? Notifier::topic($user) : null,
        ]);
    }

    /** The dropdown's contents, fetched when it opens. */
    public function list(Request $request): Response
    {
        return $this->listFor($this->user());
    }

    /** Marks one read, tells the user's other tabs, and goes where it points. */
    public function read(Request $request): Response
    {
        $user = $this->user();
        $id = $request->getAttribute('id');
        $read = is_string($id) ? $this->store->markRead($user, $id, $this->clock->now()) : null;

        if ($read === null) {
            throw new NotFoundException;
        }

        $this->notifier->announce($user);

        // Only the notice's own link, checked when it was made, or home:
        // never an address taken from the request.
        return $this->respond->redirect($read->notice->url ?? $this->home());
    }

    public function readAll(Request $request): Response
    {
        $user = $this->user();
        $this->store->markAllRead($user, $this->clock->now());
        $this->notifier->announce($user);

        return $this->listFor($user);
    }

    private function listFor(int|string $user): Response
    {
        $now = $this->clock->now()->getTimestamp();
        $items = array_map(static fn (StoredNotification $n): array => [
            'notification' => $n,
            'ago' => ($seconds = max(0, $now - $n->createdAt->getTimestamp())) < 60 ? 'just now' : Readable::duration($seconds) . ' ago',
        ], $this->store->latest($user, self::SHOWN));

        return $this->fragment('admin/partials/bell-list', [
            'items' => $items,
            'unread' => $this->store->unreadCount($user),
            'readUrl' => $this->url('/notifications'),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function fragment(string $template, array $data): Response
    {
        // Never cached: the count and the list are the moment's, and a stale
        // one is a bell that disagrees with the tab beside it.
        return $this->renderer->fragment($template, $data)->withHeader('Cache-Control', 'no-store');
    }

    private function user(): int|string
    {
        return $this->guard->id() ?? throw new NotFoundException;
    }

    private function url(string $path): string
    {
        return rtrim($this->prefix, '/') . $path;
    }

    private function home(): string
    {
        return rtrim($this->prefix, '/') ?: '/';
    }
}
