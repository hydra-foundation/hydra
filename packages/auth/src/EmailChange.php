<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\HasEmailInterface;

/** An address change a link has confirmed: whose account, and the address it moves to. */
final readonly class EmailChange
{
    public function __construct(
        public HasEmailInterface $user,
        public string $email,
    ) {}
}
