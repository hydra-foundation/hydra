<?php

declare(strict_types=1);

namespace Hydra\Tools\Changes;

/**
 * One release's notes: `changes/<x.y.z>.md`, the file the wiki renders from.
 *
 * The body is markdown and may hold raw HTML; the releases migrated from the
 * old changelog page are HTML bodies in this same format, so readers of the
 * file only ever handle one kind.
 */
final class ReleaseFile
{
    /**
     * What `bin/changes.php release` writes above the notes. `parse()` refuses
     * a file still holding it, so `check`, and with it the release preflight,
     * fails until the intro is written.
     */
    public const PLACEHOLDER_INTRO = 'Intro: what this release is, and why the number is the number.';

    private const KEYS = ['version', 'title', 'date', 'security'];
    private const VERSION = '/^\d+\.\d+\.\d+$/';

    public function __construct(
        public readonly string $version,
        public readonly string $title,
        public readonly string $date,
        public readonly bool $security,
        public readonly string $body,
    ) {
        $problem = self::versionProblem($version) ?? self::dateProblem($date)
            ?? (trim($title) === '' || str_contains($title, "\n") ? 'a title is one non-empty line' : null);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
    }

    public static function fromFile(string $path): self
    {
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new InvalidChangeFile($path, null, 'cannot be read');
        }

        return self::parse($text, $path);
    }

    public static function parse(string $text, string $file): self
    {
        $matter = FrontMatter::parse($text, $file);
        $fields = $matter->fields;
        $lines = $matter->lines;

        foreach (array_keys($fields) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidChangeFile($file, $lines[$key], "unknown key '$key'; a release file has " . implode(', ', self::KEYS));
            }
        }
        foreach (['version', 'title', 'date'] as $key) {
            if (!isset($fields[$key])) {
                throw new InvalidChangeFile($file, 1, "missing '$key'");
            }
        }

        $version = $fields['version'];
        if (($problem = self::versionProblem($version)) !== null) {
            throw new InvalidChangeFile($file, $lines['version'], $problem);
        }
        // The wiki links a release by its file name, so the two cannot differ.
        if (basename($file) !== "$version.md") {
            throw new InvalidChangeFile($file, $lines['version'], "holds $version but is named for " . basename($file, '.md') . "; rename it to $version.md");
        }
        if (($problem = self::dateProblem($fields['date'])) !== null) {
            throw new InvalidChangeFile($file, $lines['date'], $problem);
        }

        $security = $fields['security'] ?? 'false';
        if ($security !== 'true' && $security !== 'false') {
            throw new InvalidChangeFile($file, $lines['security'], "security must be true or false, not '$security'");
        }

        $at = strpos($matter->body, self::PLACEHOLDER_INTRO);
        if ($at !== false) {
            throw new InvalidChangeFile($file, $matter->bodyLine + substr_count($matter->body, "\n", 0, $at), 'the intro is still the placeholder; say what the release is and why the number is the number');
        }

        return new self($version, $fields['title'], $fields['date'], $security === 'true', $matter->body);
    }

    /**
     * `security` is written even when false, so flipping it is an edit of one
     * word rather than remembering the key.
     */
    public function render(): string
    {
        return FrontMatter::render([
            'version' => $this->version,
            'title' => $this->title,
            'date' => $this->date,
            'security' => $this->security ? 'true' : 'false',
        ], $this->body);
    }

    private static function versionProblem(string $version): ?string
    {
        return preg_match(self::VERSION, $version) ? null : "'$version' is not a version; expected x.y.z";
    }

    private static function dateProblem(string $date): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? null : "'$date' is not a date; expected YYYY-MM-DD";
    }
}
