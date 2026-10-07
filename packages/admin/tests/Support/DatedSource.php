<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use DateTimeImmutable;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;

/**
 * Rows narrowed in memory, the way a source over a log file narrows them: an
 * exact match on kind, and the day range through contains().
 *
 * The instants straddle midnight in UTC and in Regina (six hours behind), so
 * which rows a day holds says which zone it was read in.
 */
final class DatedSource implements SourceInterface
{
    public const ROWS = [
        ['id' => 1, 'subject' => 'booted', 'kind' => 'info', 'happened_at' => '2026-10-05T03:00:00+00:00'],
        ['id' => 2, 'subject' => 'crashed', 'kind' => 'error', 'happened_at' => '2026-10-05T07:00:00+00:00'],
        ['id' => 3, 'subject' => 'deployed', 'kind' => 'info', 'happened_at' => '2026-10-06T05:30:00+00:00'],
        ['id' => 4, 'subject' => 'timed out', 'kind' => 'error', 'happened_at' => '2026-10-06T06:30:00+00:00'],
    ];

    public function page(Criteria $criteria): Page
    {
        $rows = array_values(array_filter(
            self::ROWS,
            static function (array $row) use ($criteria): bool {
                foreach ($criteria->filters as $column => $value) {
                    if ((string) ($row[$column] ?? '') !== $value) {
                        return false;
                    }
                }

                $range = $criteria->ranges['happened_at'] ?? null;

                return $range === null || $range->contains(new DateTimeImmutable($row['happened_at']));
            },
        ));

        return new Page(
            array_slice($rows, $criteria->offset(), $criteria->perPage),
            count($rows),
            $criteria,
        );
    }
}
