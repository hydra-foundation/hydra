<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

/**
 * What a row's action button does. Resolved from the container, so it takes
 * whatever it needs to do it, and a module's source stays a thing that reads
 * and writes rows rather than one that knows every verb a screen might offer.
 */
interface RowActionInterface
{
    /**
     * Returns the sentence the visitor is told. Throw
     * {@see \Hydra\Admin\Exceptions\WriteRejected} to refuse, as a delete does.
     */
    public function run(string $id): string;
}
