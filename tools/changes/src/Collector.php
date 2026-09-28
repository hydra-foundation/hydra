<?php

declare(strict_types=1);

namespace Hydra\Tools\Changes;

/**
 * Turns the unreleased fragments into the body of a release file.
 *
 * Upgrading comes first, because it is the part a reader has to act on.
 * Sections follow in the order they were first seen, so the caller's order
 * (file name, for `bin/changes.php`) decides it and a new package's section
 * needs no code change. Within a section, notes run in Fragment::KINDS order,
 * and removals and security fixes are labelled so a skimming reader cannot
 * miss them.
 */
final class Collector
{
    private const LABELS = ['security' => 'Security', 'removed' => 'Removed'];

    /**
     * @param list<Fragment> $fragments
     */
    public function __construct(private readonly array $fragments) {}

    /**
     * The fragments by section, sections in first-seen order, each section's
     * notes in kind order (stable, so one kind keeps the order it came in).
     *
     * @return array<string, list<Fragment>>
     */
    public function sections(): array
    {
        $sections = [];
        foreach ($this->fragments as $fragment) {
            $sections[$fragment->section][] = $fragment;
        }

        $rank = array_flip(Fragment::KINDS);

        return array_map(static function (array $notes) use ($rank): array {
            usort($notes, static fn (Fragment $a, Fragment $b): int => $rank[$a->kind] <=> $rank[$b->kind]);

            return $notes;
        }, $sections);
    }

    /**
     * Whether the release fixes a vulnerability, which `version.json` tells
     * every installed app about.
     */
    public function security(): bool
    {
        foreach ($this->fragments as $fragment) {
            if ($fragment->kind === 'security') {
                return true;
            }
        }

        return false;
    }

    public function body(): string
    {
        $sections = $this->sections();
        $blocks = [];

        $upgrading = [];
        foreach ($sections as $section => $notes) {
            foreach ($notes as $fragment) {
                if ($fragment->upgrading !== null) {
                    $upgrading[] = self::item("**$section:** $fragment->upgrading");
                }
            }
        }
        if ($upgrading !== []) {
            $blocks[] = "## Upgrading\n\n" . implode("\n", $upgrading);
        }

        foreach ($sections as $section => $notes) {
            $items = [];
            foreach ($notes as $fragment) {
                $label = self::LABELS[$fragment->kind] ?? null;
                $items[] = self::item($label === null ? $fragment->note : "**$label.** $fragment->note");
            }
            $blocks[] = "## $section\n\n" . implode("\n", $items);
        }

        return $blocks === [] ? '' : implode("\n\n", $blocks) . "\n";
    }

    /**
     * A markdown list item. Continuation lines are indented to the item's
     * content column, so a second paragraph or a code block stays inside it.
     */
    private static function item(string $text): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if ($i > 0 && $line !== '') {
                $lines[$i] = "  $line";
            }
        }

        return '- ' . implode("\n", $lines);
    }
}
