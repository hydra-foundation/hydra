<?php

declare(strict_types=1);

namespace Hydra\Http\Testing;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What a package adds to every request a {@see Client} builds, for the part of
 * a real request the browser would have carried and http cannot know about: a
 * CSRF token, a signed-in session.
 */
interface RequestPreparer
{
    public function prepare(ServerRequestInterface $request): ServerRequestInterface;
}
