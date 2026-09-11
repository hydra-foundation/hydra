<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

/**
 * Create source interface
 *
 * How a module adds a row. Separate from {@see UpdateSourceInterface} because
 * the two are separate permissions in every application that has ever had them:
 * a table whose rows may be corrected is not thereby a table anyone may add to.
 */
interface CreateSourceInterface
{
    /**
     * The id of the row written. The admin opens that row when the module declares
     * a show screen, so this is the same id {@see RowSourceInterface::find()} is
     * asked for; a module with no screen for one row is never asked to make it
     * mean anything.
     *
     * @param array<string, mixed> $data the validated subset, keyed by input name
     */
    public function create(array $data): string;
}
