<?php

declare(strict_types=1);

namespace Hydra\Admin\Widgets;

/** Figures a health card shows a person rather than a machine. */
final class Readable
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    public static function bytes(?float $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }

        $unit = 0;

        while ($bytes >= 1024 && $unit < count(self::UNITS) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return sprintf($bytes < 10 && $unit > 0 ? '%.1f%s' : '%.0f%s', $bytes, self::UNITS[$unit]);
    }

    /** A round trip, at a precision that does not round a fast one to nothing. */
    public static function millis(float $ms): string
    {
        return ($ms < 10 ? round($ms, 1) : round($ms)) . 'ms';
    }

    /** The two largest units that say anything: "2d 4h", "9m 30s". */
    public static function duration(int $seconds): string
    {
        $parts = [];

        foreach (['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $suffix => $size) {
            $whole = intdiv($seconds, $size);
            $seconds -= $whole * $size;

            if ($whole > 0 || $parts !== []) {
                $parts[] = $whole . $suffix;
            }

            if (count($parts) === 2) {
                break;
            }
        }

        return $parts === [] ? '0s' : implode(' ', $parts);
    }
}
