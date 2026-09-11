<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

/**
 * Presenter interface
 *
 * What a page screen renders. The list-screen Source's counterpart: named as a
 * service id in the module, resolved from the container per request, so
 * declaring one never opens a connection at boot.
 */
interface PresenterInterface
{
    /** @return array<string, mixed> the page template's payload */
    public function present(): array;
}
