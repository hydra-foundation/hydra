<?php

declare(strict_types=1);

namespace Hydra\Admin\Widgets;

use Hydra\Admin\Contracts\PresenterInterface;

/** Disk, opcache and PHP memory, each as a share of what it is allowed. */
final class ResourcesWidget implements PresenterInterface
{
    /** Also what the card reserves room for, so the two cannot drift. */
    public const GAUGES = 3;

    private const CROWDED = 75;
    private const FULL = 90;

    public function present(): array
    {
        return [
            'gauges' => array_values(array_filter([
                $this->disk(),
                $this->opcache(),
                $this->memory(),
            ], static fn (?array $gauge): bool => $gauge !== null)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function disk(): ?array
    {
        $root = dirname(__DIR__, 3);
        $total = @disk_total_space($root);
        $free = @disk_free_space($root);

        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        return $this->gauge(
            'Disk',
            $total - $free,
            $total,
            sprintf('%s free of %s', Readable::bytes($free), Readable::bytes($total)),
        );
    }

    /** @return array<string, mixed> */
    private function opcache(): array
    {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        if ($status === false) {
            return [
                'label' => 'Opcache',
                'figure' => 'off',
                'share' => null,
                'caption' => 'Every request recompiles the application.',
                'tone' => Status::Warning->tone(),
            ];
        }

        $memory = $status['memory_usage'];
        $used = (float) $memory['used_memory'];
        $total = $used + (float) $memory['free_memory'] + (float) $memory['wasted_memory'];
        $statistics = $status['opcache_statistics'] ?? [];
        $hits = (int) ($statistics['hits'] ?? 0);
        $misses = (int) ($statistics['misses'] ?? 0);
        $reads = $hits + $misses;

        return $this->gauge(
            'Opcache',
            $used,
            $total,
            sprintf(
                '%s of %s, %s cached%s',
                Readable::bytes($used),
                Readable::bytes($total),
                number_format((int) ($statistics['num_cached_scripts'] ?? 0)),
                $reads === 0 ? '' : ', ' . round($hits / $reads * 100) . '% hit rate',
            ),
        );
    }

    /**
     * Peak for this request against the limit. It measures the card's own
     * request and not the busiest one, so it is a sense of scale rather than a
     * watermark; the limit beside it is the figure worth knowing.
     *
     * @return array<string, mixed>
     */
    private function memory(): array
    {
        $limit = $this->limit();
        $peak = (float) memory_get_peak_usage(true);

        if ($limit === null) {
            return [
                'label' => 'PHP memory',
                'figure' => (string) Readable::bytes($peak),
                'share' => null,
                'caption' => 'No limit set; peak this request.',
                'tone' => '',
            ];
        }

        return $this->gauge(
            'PHP memory',
            $peak,
            $limit,
            sprintf('%s peak this request, limit %s', Readable::bytes($peak), Readable::bytes($limit)),
        );
    }

    private function limit(): ?float
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return null;
        }

        $scale = match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (float) rtrim($limit, 'gGmMkK') * $scale;
    }

    /** @return array<string, mixed> */
    private function gauge(string $label, float $used, float $total, string $caption): array
    {
        $share = (int) round($used / max($total, 1.0) * 100);

        return [
            'label' => $label,
            'figure' => $share . '%',
            'share' => min($share, 100),
            'caption' => $caption,
            'tone' => match (true) {
                $share >= self::FULL => Status::Down->tone(),
                $share >= self::CROWDED => Status::Warning->tone(),
                default => '',
            },
        ];
    }
}
