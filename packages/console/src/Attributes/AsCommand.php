<?php

declare(strict_types=1);

namespace Hydra\Console\Attributes;

use Attribute;

/**
 * The name a command answers to, and the line describing it in the list.
 *
 * An attribute rather than a method, for the same reason {@see \Hydra\Http\Attributes\Route}
 * is one: it can be read from the class string without building the object.
 * That is what lets a console register `migrate:fresh` as a name pointing at a
 * factory, and only construct the command — and so open the database
 * connection it needs — once that name is the one being run.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsCommand
{
    public function __construct(
        public string $name,
        public string $description = '',
    ) {}
}
