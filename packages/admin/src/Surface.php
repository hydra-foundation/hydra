<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * Where a field appears. One field declaration projects onto many surfaces, all
 * of them read-only renderings: a writable control is not a Surface, because a
 * form needs the stored value back, not a formatted one.
 */
enum Surface: string
{
    case List = 'list';
    case Show = 'show';

    /**
     * A column of an extracted file rather than of a page. It is its own
     * surface because a spreadsheet is not a table: a column too wide to sit in
     * a row still belongs in the export, and a formatter written for a cell
     * ("3 minutes ago", a badge) is the wrong thing to put in one. Declare
     * format() per surface when the two should differ; {@see Csv} reduces
     * markup that reaches it to the text inside, which keeps a shared formatter
     * honest rather than correct.
     */
    case Export = 'export';
}
