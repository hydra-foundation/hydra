<?php

declare(strict_types=1);

namespace Hydra\Auth\Contracts;

/**
 * A user with an address auth can send links to. Opt-in, since auth owns no
 * user storage and cannot assume an email column.
 */
interface HasEmailInterface extends AuthenticatableInterface
{
    public function getAuthEmail(): string;
}
