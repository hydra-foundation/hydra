<?php

declare(strict_types=1);

namespace Hydra\Database;

use DateTimeImmutable;

/**
 * Where the schema stands against the migrations on disk, as data rather than
 * console output: what a health card reads after a deploy.
 */
final readonly class MigrationSummary
{
    public function __construct(
        /** @var list<string> filenames recorded as applied, in filename order, including any whose file is gone */
        public array $applied,
        /** @var list<string> filenames on disk not yet applied, in filename order */
        public array $pending,
        /** The migration applied most recently, by applied_at then filename. */
        public ?string $last,
        public ?DateTimeImmutable $lastAt,
    ) {}
}
