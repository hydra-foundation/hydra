<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\Extractor;

/**
 * The list as a file: every row the current filters, search and order match,
 * written as CSV for the fields declared on {@see \Hydra\Admin\Surface::Export}.
 *
 * Declared and never inferred, unlike the list screen a module with a source
 * and fields gets for free. An export hands a whole table to whoever can open
 * the screen, past the pagination that otherwise bounds what one request
 * costs, and that is a decision a module makes rather than one it inherits.
 * requires() is here for the same reason: the ability to read a page of
 * something is not obviously the ability to walk out with all of it.
 */
final class ExportScreen implements ScreenInterface
{
    private ?string $ability = null;
    private ?string $filename = null;
    private string $label = 'Export CSV';
    private int $limit = Extractor::MAX_ROWS;

    private function __construct(private readonly string $path) {}

    public static function make(string $path = 'export'): self
    {
        return new self($path);
    }

    /** @param class-string|null $ability */
    public function requires(?string $ability): self
    {
        $clone = clone $this;
        $clone->ability = $ability;

        return $clone;
    }

    /**
     * The downloaded file's base name. The date and the extension are added;
     * left alone it is the module's slug.
     */
    public function named(string $filename): self
    {
        $clone = clone $this;
        $clone->filename = $filename;

        return $clone;
    }

    /**
     * How many rows one download may carry. {@see Extractor::MAX_ROWS} is the
     * default and not a ceiling: a module that means to export a million rows
     * may say so, and then owns what that request costs.
     */
    public function limit(int $rows): self
    {
        $clone = clone $this;
        $clone->limit = max(1, $rows);

        return $clone;
    }

    /** What the button on the list says. */
    public function labelled(string $label): self
    {
        $clone = clone $this;
        $clone->label = $label;

        return $clone;
    }

    public function name(): string
    {
        return 'export';
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): array
    {
        return [AdminController::class, 'export'];
    }

    public function ability(): ?string
    {
        return $this->ability;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function rowLimit(): int
    {
        return $this->limit;
    }

    /**
     * The name the browser saves the file under, dated so that two exports of
     * the same list do not land on top of each other in a downloads folder.
     * Reduced to characters a file name carries everywhere, because this ends
     * up in a header and then on somebody's disk.
     */
    public function filename(string $slug): string
    {
        $base = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $this->filename ?? $slug);
        $base = trim($base, '-.');

        return ($base === '' ? 'export' : $base) . '-' . date('Y-m-d') . '.csv';
    }
}
