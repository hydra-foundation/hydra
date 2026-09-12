<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

/**
 * A screen that can also answer a POST at its own URL. The scanner emits the
 * second route; nothing else about the screen changes, so a submission is still
 * just a request to the URL the form is already at.
 *
 * A form is always submitted and says so by returning a handler. A page is only
 * sometimes — a dashboard reads, a settings page saves — and answers null until
 * it is given one, which is what keeps the extra route off every page that has
 * nothing to receive.
 */
interface SubmittableInterface
{
    /** @return array{0: class-string, 1: string}|null */
    public function submitHandler(): ?array;
}
