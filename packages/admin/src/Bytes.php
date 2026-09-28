<?php

declare(strict_types=1);

namespace Hydra\Admin;

/** A size as a person reads one: "13.3 KB", "512 KB", "1.5 MB". */
final class Bytes
{
    private const UNITS = ['KB', 'MB', 'GB', 'TB'];

    private function __construct() {}

    /**
     * In the largest binary unit the size fills, to one decimal place, with a
     * whole number left whole. No thousands separator, so a size is one token.
     */
    public static function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $size = $bytes / 1024;
        $unit = 0;

        while ($size >= 1024 && $unit < count(self::UNITS) - 1) {
            $size /= 1024;
            $unit++;
        }

        $rounded = number_format($size, 1, '.', '');

        return (str_ends_with($rounded, '.0') ? substr($rounded, 0, -2) : $rounded) . ' ' . self::UNITS[$unit];
    }
}
