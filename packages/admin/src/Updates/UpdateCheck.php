<?php

declare(strict_types=1);

namespace Hydra\Admin\Updates;

use Hydra\Admin\Contracts\ReleaseFeedInterface;
use Hydra\Cache\Contracts\StoreInterface;
use Throwable;

/**
 * Reads the release feed against the installed Hydra, at most twice a day. A
 * feed that failed is remembered as well, for an hour, so a host with no route
 * out pays the timeout once an hour rather than on every admin page.
 */
final class UpdateCheck
{
    private const KEY = 'admin:updates:feed';

    private const TTL = 43200;

    private const RETRY = 3600;

    private const RELEASE = '/^\d+\.\d+\.\d+$/';

    private ?Update $update = null;

    public function __construct(
        private readonly ReleaseFeedInterface $feed,
        private readonly StoreInterface $store,
        private readonly string $installed,
        private readonly bool $enabled = true,
    ) {}

    public function update(): Update
    {
        return $this->update ??= $this->resolve();
    }

    private function resolve(): Update
    {
        $installed = ltrim($this->installed, 'v');

        if (!$this->enabled) {
            return new Update(Standing::Off, $installed);
        }

        if (preg_match(self::RELEASE, $installed) !== 1) {
            return new Update(Standing::Unreleased, $installed);
        }

        $releases = $this->releases();

        return $releases === null ? new Update(Standing::Unknown, $installed) : $this->compare($installed, $releases);
    }

    /**
     * @param array{latest: string, series: array<string, string>, security: list<string>, notes: ?string} $releases
     */
    private function compare(string $installed, array $releases): Update
    {
        $series = self::series($installed);
        $newest = $releases['series'][$series] ?? null;

        if ($newest !== null && version_compare($newest, $installed, '>')) {
            $security = array_filter(
                $releases['security'],
                static fn (string $v): bool => self::series($v) === $series
                    && version_compare($v, $installed, '>')
                    && version_compare($v, $newest, '<='),
            );

            return new Update(Standing::Patch, $installed, $newest, $security !== [], self::notes($releases['notes'], $newest));
        }

        if (version_compare($releases['latest'], $installed, '>')) {
            return new Update(Standing::Series, $installed, $releases['latest'], false, self::notes($releases['notes'], $releases['latest']));
        }

        return new Update(Standing::Current, $installed);
    }

    /**
     * An empty array is a remembered failure; no valid feed is empty.
     *
     * @return array{latest: string, series: array<string, string>, security: list<string>, notes: ?string}|null
     */
    private function releases(): ?array
    {
        try {
            $cached = $this->store->get(self::KEY);
        } catch (Throwable) {
            // With nowhere to remember the answer, every admin page would fetch it.
            return null;
        }

        if (is_array($cached)) {
            return $cached === [] ? null : $cached;
        }

        $releases = self::parse($this->feed->fetch());

        try {
            $this->store->put(self::KEY, $releases ?? [], $releases === null ? self::RETRY : self::TTL);
        } catch (Throwable) {
        }

        return $releases;
    }

    /**
     * @param array<mixed>|null $feed
     * @return array{latest: string, series: array<string, string>, security: list<string>, notes: ?string}|null
     */
    private static function parse(?array $feed): ?array
    {
        $latest = $feed['latest'] ?? null;

        if (!is_string($latest) || preg_match(self::RELEASE, $latest) !== 1) {
            return null;
        }

        $series = [];

        foreach (is_array($feed['series'] ?? null) ? $feed['series'] : [] as $name => $version) {
            if (is_string($version) && preg_match(self::RELEASE, $version) === 1 && self::series($version) === (string) $name) {
                $series[(string) $name] = $version;
            }
        }

        $security = array_values(array_filter(
            is_array($feed['security'] ?? null) ? $feed['security'] : [],
            static fn (mixed $v): bool => is_string($v) && preg_match(self::RELEASE, $v) === 1,
        ));

        $notes = $feed['notes'] ?? null;

        return [
            'latest' => $latest,
            'series' => $series,
            'security' => $security,
            'notes' => is_string($notes) && str_starts_with($notes, 'https://') ? $notes : null,
        ];
    }

    private static function series(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /** The changelog anchors each release as v0-9-7. */
    private static function notes(?string $notes, string $version): ?string
    {
        return $notes === null ? null : $notes . '#v' . str_replace('.', '-', $version);
    }
}
