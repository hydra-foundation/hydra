<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

use Hydra\Admin\Files\Reference;

/**
 * Something that holds file keys the admin cannot find by walking a module:
 * files the application stored in a table no module describes, or rows a
 * module's list leaves out (soft-deleted ones, say) whose files are still in
 * use.
 *
 * Two places take one. An application lists its own in the
 * AdminServiceProvider's fileHolders, and a module's source that implements it
 * is asked for its references instead of being walked page by page.
 *
 * A key left out here is a file the Files module calls an orphan, and an
 * orphan can be deleted. When in doubt, include it.
 */
interface FileHolderInterface
{
    /** @return iterable<Reference> */
    public function references(): iterable;
}
