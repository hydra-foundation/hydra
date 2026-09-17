<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Config;

/**
 * The policy's three switches, as one bound object.
 *
 * It is a container binding rather than a constructor argument because a test
 * has to be able to rebind it: the enforced, report-only and disabled cases are
 * three different applications as far as the layout and the middleware are
 * concerned, and building three containers to see them would hide the fact that
 * both of them read the same one.
 */
final readonly class CspConfig
{
    public function __construct(
        public bool $enabled = true,
        public bool $reportOnly = false,
        public string $reportUri = '',
    ) {}
}
