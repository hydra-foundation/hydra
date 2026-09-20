<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

use Hydra\Admin\Window;

/**
 * A presenter that answers for a stretch of time rather than for everything.
 *
 * The window is handed in rather than read from a clock, so that every card on
 * one dashboard counts between the same two instants, and so that a presenter
 * can be asked about last week in a test without moving the clock.
 *
 * Declaring this is half of it: the widget has to say {@see \Hydra\Admin\Widget::periodic()}
 * as well, because the grid decides what to redraw before any presenter is
 * resolved. {@see \Hydra\Admin\ModuleRegistry::presentWidget()} refuses the
 * pair when only one of them says so.
 */
interface PeriodAwareInterface extends PresenterInterface
{
    /** The same presenter, reading the window given. */
    public function withWindow(Window $window): static;
}
