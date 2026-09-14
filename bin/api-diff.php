<?php

declare(strict_types=1);

/*
 * Compare two surface listings from bin/api-surface.php and print the lines
 * that were removed, one per line. Exits 0 either way; the caller decides what
 * a removal costs.
 *
 *   php bin/api-surface.php old/packages > /tmp/old
 *   php bin/api-surface.php packages     > /tmp/new
 *   php bin/api-diff.php /tmp/old /tmp/new
 *
 * This exists because `comm -23` cannot tell the two kinds of signature change
 * apart. Putting the signature on the line is what let the gate see a required
 * parameter appear; it also made every added optional parameter look like a
 * removal, and that is a change consumers do not have to act on. A gate that
 * cries break on a compatible edit gets --minor by reflex, which costs the same
 * as not having a gate, so the one compatible shape is recognised here: the new
 * parameter list starts with the old one and everything after it is optional.
 *
 * Nothing else is forgiven. A renamed parameter, a widened type, a reordered
 * list and `?Foo` rewritten as `Foo|null` all still read as removals, because
 * named arguments make parameter names public and because a gate that guesses
 * should guess toward the tag that cannot break anyone.
 */

if ($argc !== 3) {
    fwrite(STDERR, "usage: api-diff.php <old-surface> <new-surface>\n");

    exit(1);
}

$read = static function (string $path): array {
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        fwrite(STDERR, "api-diff.php: cannot read $path\n");

        exit(1);
    }

    return $lines;
};

$old = $read($argv[1]);
$new = $read($argv[2]);

/**
 * Split a callable line into its symbol, its parameters and everything after
 * the closing parenthesis. Null when the line is not a callable.
 *
 * Depth-counted rather than matched with a regex: a default value may carry
 * parentheses of its own, and `new Foo()` in a promoted property would end the
 * signature early.
 */
$parse = static function (string $line): ?array {
    if (preg_match('/^([^\s(]+)\(/', $line, $m) !== 1) {
        return null;
    }

    $open = strlen($m[1]);
    $depth = 0;

    for ($i = $open, $len = strlen($line); $i < $len; $i++) {
        $depth += match ($line[$i]) {
            '(', '[', '{' => 1,
            ')', ']', '}' => -1,
            default => 0,
        };

        if ($depth === 0) {
            return [$m[1], substr($line, $open + 1, $i - $open - 1), substr($line, $i + 1)];
        }
    }

    return null;
};

/** Split a parameter list on the commas that separate parameters. */
$split = static function (string $params): array {
    $params = trim($params);

    if ($params === '') {
        return [];
    }

    $out = [];
    $depth = 0;
    $start = 0;

    for ($i = 0, $len = strlen($params); $i < $len; $i++) {
        $depth += match ($params[$i]) {
            '(', '[', '{' => 1,
            ')', ']', '}' => -1,
            default => 0,
        };

        if ($depth === 0 && $params[$i] === ',') {
            $out[] = trim(substr($params, $start, $i - $start));
            $start = $i + 1;
        }
    }

    $out[] = trim(substr($params, $start));

    return $out;
};

// New callables by symbol, so an old line can find the signature that replaced
// it. A symbol declared once per tree, so the last wins is the only wins.
$replacements = [];

foreach ($new as $line) {
    $parts = $parse($line);

    if ($parts !== null) {
        $replacements[$parts[0]] = $parts;
    }
}

$present = array_flip($new);
$removed = [];

foreach ($old as $line) {
    if (isset($present[$line])) {
        continue;
    }

    $was = $parse($line);
    $now = $was === null ? null : ($replacements[$was[0]] ?? null);

    if ($now === null || $was[2] !== $now[2]) {
        $removed[] = $line;

        continue;
    }

    $before = $split($was[1]);
    $after = $split($now[1]);

    // Everything the old signature named has to survive unchanged and in
    // place; a caller's positional arguments land on those same slots.
    if (array_slice($after, 0, count($before)) !== $before) {
        $removed[] = $line;

        continue;
    }

    foreach (array_slice($after, count($before)) as $added) {
        if (!str_contains($added, '=') && !str_contains($added, '...')) {
            $removed[] = $line;

            break;
        }
    }
}

echo $removed === [] ? '' : implode("\n", $removed)."\n";
