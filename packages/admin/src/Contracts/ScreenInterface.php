<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

/**
 * A thing at a URL under a module. Every screen (list, form, report, whatever
 * an application invents) is one of these, and names the controller method
 * that answers it.
 */
interface ScreenInterface
{
    public function name(): string;

    public function method(): string;

    /** Path relative to the module root, '' for the module root itself. */
    public function path(): string;

    /** @return array{0: class-string, 1: string} */
    public function handler(): array;

    /** Ability required for this screen, or null to inherit the module's. */
    public function ability(): ?string;
}
