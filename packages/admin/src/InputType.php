<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * The control a writable field renders as.
 */
enum InputType: string
{
    case Text = 'text';
    case Email = 'email';
    case Password = 'password';
    case Textarea = 'textarea';
    case Select = 'select';
}
