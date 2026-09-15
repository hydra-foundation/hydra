<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * Reads the id a screen was given as the integer key a table actually has, or
 * refuses it.
 *
 * The admin takes a row's id out of the URL, so what reaches a source is any
 * string somebody could type. `(int)` on one of those is the quiet failure:
 * PHP reads "1-not-an-id", "1 OR 1=1" and "1%" all as 1, so a source casting
 * straight to int answers a URL naming no row with the row next to it, and
 * {@see Contracts\DeleteSourceInterface::delete()} — which the controller does
 * not look up first — removes it.
 *
 * Nothing here is about injection: every source binds the value. It is about a
 * screen answering for the row it was asked for and no other.
 */
final class RowId
{
    /**
     * The id as an integer key, or null when the string is not exactly one.
     *
     * Exactly: the round trip has to spell the same string back, so "01", " 1"
     * and "1.0" are all misses. A table's key has one spelling, and accepting
     * several gives one row as many URLs.
     */
    public static function int(string $id): ?int
    {
        return (string) (int) $id === $id ? (int) $id : null;
    }
}
