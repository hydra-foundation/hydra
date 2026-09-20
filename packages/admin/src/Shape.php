<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * What a card's body is made of, which is the only thing that turns a count of
 * items into a height.
 *
 * {@see Widget::reserving()} says how many items a card will come back holding.
 * How tall that is depends entirely on what an item looks like: a row of a list
 * is not a ranked bar with a caption under it, and neither is a chart. Without
 * this the count was measured in an abstract bar that matched no real body, so
 * the same integer meant three different heights and every number in a module
 * was tuned by eye against the wrong unit.
 *
 * The value is the class the placeholder wears, so the stride lives in one CSS
 * rule per shape rather than in a module's arithmetic.
 */
enum Shape: string
{
    /** Short lines of text: the default, and what a figure and a caption are. */
    case Lines = 'lines';

    /** A list: each item a link with a caption under it. */
    case Rows = 'rows';

    /** A ranking: each item a heading, a bar and a caption. */
    case Bars = 'bars';

    /** One solid area rather than items — a chart. Reserve 1 of these. */
    case Block = 'block';
}
