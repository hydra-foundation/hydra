<?php

declare(strict_types=1);

namespace Hydra\Tools\Changes;

/**
 * Flat `key: value` lines between `---` fences, then a markdown body.
 *
 * Deliberately not YAML: the tool stays dependency-free, and the files only
 * ever need a handful of scalar fields. Anything YAML would accept that this
 * does not (nesting, lists, a repeated key) is an error naming the line,
 * rather than a value silently read some other way.
 */
final class FrontMatter
{
    private const FENCE = '---';
    private const FIELD = '/^([a-z][a-z0-9_]*):(.*)$/';

    /**
     * @param array<string, string> $fields
     * @param array<string, int> $lines the line each field is on, for errors raised after parsing
     * @param int $bodyLine the line the (trimmed) body starts on
     */
    private function __construct(
        public readonly array $fields,
        public readonly array $lines,
        public readonly string $body,
        public readonly int $bodyLine,
    ) {}

    public static function parse(string $text, string $file): self
    {
        $rows = explode("\n", str_replace("\r\n", "\n", $text));

        if (rtrim($rows[0]) !== self::FENCE) {
            throw new InvalidChangeFile($file, 1, 'must open with --- (front matter, then the body)');
        }

        // Found first, so a missing fence is reported as that rather than as
        // whatever body line the field loop would trip over.
        $close = null;
        foreach (array_slice($rows, 1, null, true) as $i => $row) {
            if (rtrim($row) === self::FENCE) {
                $close = $i;
                break;
            }
        }
        if ($close === null) {
            throw new InvalidChangeFile($file, 1, 'the front matter opened here is never closed with ---');
        }

        $fields = [];
        $lines = [];

        for ($i = 1; $i < $close; $i++) {
            $row = rtrim($rows[$i]);
            $line = $i + 1;

            if ($row === '') {
                continue;
            }
            if ($row[0] === ' ' || $row[0] === "\t") {
                throw new InvalidChangeFile($file, $line, 'front matter is flat key: value lines; nothing is indented');
            }
            if (!preg_match(self::FIELD, $row, $m)) {
                throw new InvalidChangeFile($file, $line, "expected key: value (a lower-case key), got '$row'");
            }

            [, $key, $value] = $m;
            $value = trim($value);

            if (isset($lines[$key])) {
                throw new InvalidChangeFile($file, $line, "duplicate key '$key' (first on line {$lines[$key]})");
            }
            if ($value === '') {
                throw new InvalidChangeFile($file, $line, "'$key' has no value; front matter is flat, so nothing nests under it");
            }

            $fields[$key] = $value;
            $lines[$key] = $line;
        }

        // Leading blank lines are dropped, and the body line moves with them,
        // so a line counted from the body still lands on the right row.
        // An empty body points at the line after the fence.
        $bodyLine = $close + 2;
        $rest = array_slice($rows, $close + 1);
        while ($rest !== [] && trim($rest[0]) === '') {
            array_shift($rest);
            $bodyLine++;
        }
        if ($rest === []) {
            $bodyLine = $close + 2;
        }

        return new self($fields, $lines, rtrim(implode("\n", $rest)), $bodyLine);
    }

    /**
     * The text `parse()` reads back as the same fields and body.
     *
     * @param array<string, string> $fields
     */
    public static function render(array $fields, string $body): string
    {
        $text = self::FENCE . "\n";
        foreach ($fields as $key => $value) {
            if (!preg_match(self::FIELD, "$key:") || trim($value) === '' || trim($value) !== $value || str_contains($value, "\n")) {
                throw new \InvalidArgumentException("'$key' cannot be written as front matter: a lower-case key and a one-line value with no padding");
            }
            $text .= "$key: $value\n";
        }
        $text .= self::FENCE . "\n";

        $body = trim($body, "\n");

        return $body === '' ? $text : "$text$body\n";
    }
}
