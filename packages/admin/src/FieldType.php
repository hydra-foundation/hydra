<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * How a field's value is rendered, and nothing about how it is stored. A column
 * is a column to the source; this is only what the template does with it.
 */
enum FieldType: string
{
    case Id = 'id';
    case Text = 'text';
    case Select = 'select';
    case DateTime = 'datetime';
}
