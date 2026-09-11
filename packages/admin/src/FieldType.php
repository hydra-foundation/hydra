<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * Field type
 */
enum FieldType: string
{
    case Id = 'id';
    case Text = 'text';
    case Select = 'select';
    case DateTime = 'datetime';
}
