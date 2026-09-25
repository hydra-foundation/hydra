<?php

declare(strict_types=1);

namespace Hydra\Admin\Testing;

use Hydra\Admin\Contracts\ReleaseFeedInterface;

/** A feed that answers with whatever it was given, and counts how often it was asked. */
final class FakeReleaseFeed implements ReleaseFeedInterface
{
    public int $fetches = 0;

    /** @param array<mixed>|null $feed */
    public function __construct(private readonly ?array $feed = null) {}

    /**
     * A feed naming these releases, the highest of them the latest.
     *
     * @param list<string> $versions
     * @param list<string> $security
     */
    public static function releases(array $versions, array $security = []): self
    {
        usort($versions, 'version_compare');
        $series = [];

        foreach ($versions as $version) {
            $series[implode('.', array_slice(explode('.', $version), 0, 2))] = $version;
        }

        return new self([
            'latest' => end($versions),
            'series' => $series,
            'security' => $security,
            'notes' => 'https://hydra.example/docs/changelog.html',
        ]);
    }

    public function fetch(): ?array
    {
        $this->fetches++;

        return $this->feed;
    }
}
