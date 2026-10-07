<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use DateTimeImmutable;
use Hydra\Admin\Sources\ContentSource;
use Hydra\View\Content\ContentDirectory;

/** The Posts source a blog writes: front matter, plus a status the site derives. */
final class PostSource extends ContentSource
{
    public function __construct(string $directory, DateTimeImmutable $now)
    {
        parent::__construct(
            new ContentDirectory($directory, new LineFrontMatter),
            columns: ['slug', 'title', 'date', 'tags', 'status'],
            sortable: ['title', 'date'],
            searchable: ['title', 'tags', 'body'],
            filterable: ['date', 'tags', 'status'],
            defaultSort: 'date',
            map: static fn (array $row): array => [
                ...$row,
                'status' => match (true) {
                    ($row['draft'] ?? false) === true => 'draft',
                    ($row['date'] ?? null) > $now => 'scheduled',
                    default => 'published',
                },
            ],
        );
    }
}
