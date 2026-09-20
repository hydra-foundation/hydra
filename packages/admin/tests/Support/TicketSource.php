<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;

/**
 * Rows matched on equality in memory, the way a table source matches them in
 * SQL. A filter link is only worth testing against a source that honours a
 * filter, since the whole failure it can have is one that does not.
 */
final class TicketSource implements SourceInterface
{
    private const ROWS = [
        ['id' => 1, 'subject' => 'cannot sign in', 'status' => 'open'],
        ['id' => 2, 'subject' => 'invoice is wrong', 'status' => 'open'],
        ['id' => 3, 'subject' => 'refund issued', 'status' => 'closed'],
        ['id' => 4, 'subject' => 'duplicate account', 'status' => 'closed'],
        ['id' => 5, 'subject' => 'password reset', 'status' => 'pending'],
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

                return true;
            },
        ));

        return new Page(
            array_slice($rows, $criteria->offset(), $criteria->perPage),
            count($rows),
            $criteria,
        );
    }
}
