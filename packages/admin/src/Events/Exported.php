<?php

declare(strict_types=1);

namespace Hydra\Admin\Events;

use Hydra\Admin\Criteria;

/**
 * A module's list left as a file.
 *
 * The one admin action that moves a whole table at once, and the reason this
 * event exists at all: every other screen hands over a page or a row, and each
 * of those leaves a trail of its own in the request log. An export does not —
 * one GET and forty thousand rows are gone, and nothing about the URL says so.
 */
final class Exported extends AdminEvent
{
    public function __construct(
        string $module,
        public readonly Criteria $criteria,
        public readonly int $rows,
    ) {
        parent::__construct($module);
    }

    public function action(): string
    {
        return 'admin.exported';
    }

    /**
     * The view is carried as the query string that produced it, which is both
     * how the criteria already know how to describe themselves and something a
     * reader can paste back into the admin to see what was taken.
     */
    public function context(): array
    {
        return [
            'module' => $this->module,
            'rows' => $this->rows,
            'view' => $this->criteria->toQuery(),
        ];
    }
}
