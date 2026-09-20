<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Link;

/**
 * A module with filter links over the same column its toolbar filters, which is
 * the arrangement where the two can disagree.
 */
final class TicketsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('tickets')
            ->source(TicketSource::class)
            ->defaultSort('id')
            ->links(
                Link::make('All'),
                Link::make('Open')->where('status', 'open'),
                Link::make('Closed')->where('status', 'closed'),
            )
            ->fields(
                Field::id(),
                Field::text('subject')->sortable(),
                Field::select('status', [
                    'open' => 'Open',
                    'closed' => 'Closed',
                    'pending' => 'Pending',
                ])->filterable(),
            );
    }
}
