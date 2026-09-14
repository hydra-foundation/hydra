<?php

/*
 * Print the public surface under a packages/ directory, one symbol per line:
 * a class-like name, Class::method with its full signature, Class::CONST, and
 * an enum's cases. Properties are not walked — constructor promotion makes
 * them ambiguous to read this way, and nothing has been broken by one yet.
 *
 * bin/release.sh diffs the output at the previous tag against the output here.
 * A line that disappears is a break, and ^0.3 promises consumers there are
 * none inside the series.
 *
 * The signature is on the line because a name on its own cannot see one
 * change. 0.5.0 gave PhpView::__construct a required second parameter and the
 * comparison reported nothing removed, because the method still answered to
 * its own name; `bin/release.sh 0.4.2` would have shipped that as a patch.
 * With the signature on the line, any change to one reads as a removal plus an
 * addition, and the removal is what the gate already refuses.
 *
 * Parsed from tokens rather than reflected: the other side of the comparison
 * is a detached worktree of an old tag with no vendor/ in it, so there is no
 * autoloader to reflect against without installing one per release.
 *
 * The signature is compared as written, so `?Foo` changing to `Foo|null`
 * reads as a break it is not. That is the safe direction for a gate whose
 * only cost is passing --minor, and a renamed parameter genuinely is one:
 * named arguments make every parameter name public. The one change that is
 * common enough to be worth telling apart — an optional parameter appended to
 * a list that is otherwise untouched — is recognised in bin/api-diff.php,
 * which is what compares two of these listings.
 */

$root = $argv[1] ?? null;

if ($root === null || !is_dir($root)) {
    fwrite(STDERR, "usage: api-surface.php <packages-dir>\n");
    exit(1);
}

/*
 * src/Testing is deliberately not scanned. It holds the published contract
 * cases, and what a listing of them records is their public test methods —
 * exactly the members a subclass cannot break by losing. The parts that would
 * break one are the protected abstract hooks, which are not surface and never
 * appear here, so including the directory is all churn and no signal.
 */
$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
    '#/src/(?!Testing/).+\.php$#'
);

$symbols = [];

/** Whether a token carries no meaning for a signature. */
$noise = static fn (PhpToken $t): bool => $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]);

/**
 * Render tokens $from..$to as one line: spaced where a space separates words,
 * closed up where it only ever decorated punctuation, so two spellings of the
 * same signature cannot read as a change.
 *
 * @param list<PhpToken> $tokens
 */
$render = static function (array $tokens, int $from, int $to) use ($noise): string {
    // No space on that side, because one there is formatting rather than syntax.
    $tight = [
        'before' => [')', ',', ';', '::', '|', '&', ']', ':'],
        'after' => ['(', '?', '::', '|', '&', '\\', '['],
    ];

    $out = '';
    $previous = null;

    for ($i = $from; $i <= $to; $i++) {
        if ($noise($tokens[$i])) {
            continue;
        }

        $text = $tokens[$i]->text;

        if ($out !== ''
            && !in_array($text, $tight['before'], true)
            && !in_array($previous, $tight['after'], true)
        ) {
            $out .= ' ';
        }

        $out .= $text;
        $previous = $text;
    }

    // A trailing comma is a diff in the file and nothing to a caller.
    return str_replace(',)', ')', $out);
};

foreach ($files as $file) {
    $tokens = PhpToken::tokenize(file_get_contents($file->getPathname()));
    $count = count($tokens);

    $namespace = '';
    $class = null;
    $isEnum = false;
    $classDepth = 0;
    $depth = 0;
    $modifiers = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($noise($token)) {
            continue;
        }

        if ($token->text === '{') {
            $depth++;
            continue;
        }

        if ($token->text === '}') {
            $depth--;

            if ($class !== null && $depth < $classDepth) {
                $class = null;
                $isEnum = false;
            }

            continue;
        }

        /** The next token that carries meaning, or null at the end. */
        $next = static function (int $from) use ($tokens, $count, $noise): ?int {
            for ($j = $from; $j < $count; $j++) {
                if (!$noise($tokens[$j])) {
                    return $j;
                }
            }

            return null;
        };

        $after = $next($i + 1);
        $nextToken = $after === null ? null : $tokens[$after];

        if ($token->is(T_NAMESPACE) && $nextToken?->is([T_NAME_QUALIFIED, T_STRING])) {
            $namespace = $nextToken->text;
            continue;
        }

        // ::class is a T_CLASS too, and `new class` has no name to record.
        $previous = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!$noise($tokens[$j])) {
                $previous = $tokens[$j];
                break;
            }
        }

        if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])
            && !($previous?->is(T_DOUBLE_COLON) ?? false)
            && $nextToken?->is(T_STRING)
        ) {
            $class = $namespace . '\\' . $nextToken->text;
            $isEnum = $token->is(T_ENUM);
            $classDepth = $depth + 1;
            $symbols[] = $class;
            $modifiers = [];
            continue;
        }

        if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY])) {
            $modifiers[] = $token->id;
            continue;
        }

        // Only members of the class body itself: a `function` or a `case`
        // deeper than that belongs to a closure or a switch.
        $inClassBody = $class !== null && $depth === $classDepth;

        // An interface method and a bare `const` declare no visibility and are
        // public; anything explicitly hidden is not surface.
        $hidden = array_intersect($modifiers, [T_PROTECTED, T_PRIVATE]) !== [];

        if ($inClassBody && $token->is(T_FUNCTION) && $nextToken?->is(T_STRING)) {
            $name = $nextToken->text;

            // From the parameter list to whatever ends the declaration: `{` for
            // a body, `;` for an abstract or interface method.
            $open = $next($after + 1);
            $end = $open;
            $parens = 0;

            for ($j = $open; $j !== null && $j < $count; $j++) {
                $text = $tokens[$j]->text;

                if ($text === '(') {
                    $parens++;
                } elseif ($text === ')') {
                    $parens--;
                } elseif ($parens === 0 && ($text === '{' || $text === ';')) {
                    break;
                }

                $end = $j;
            }

            if (!$hidden) {
                // PHP's own notation: :: for static, -> for instance. A method
                // that changes from one to the other is a break, and spelling
                // it this way is what makes the comparison see it.
                $arrow = in_array(T_STATIC, $modifiers, true) ? '::' : '->';

                $symbols[] = $class . $arrow . $name . $render($tokens, $open, $end);
            }

            $modifiers = [];
            // Past the declaration, so a promoted property's visibility
            // keyword cannot be read as the next member's.
            $i = $end;
            continue;
        }

        if ($inClassBody && $token->is(T_CONST) && $nextToken?->is(T_STRING) && !$hidden) {
            $symbols[] = $class . '::' . $nextToken->text;
        }

        // An enum's cases are as public as its name, and a removed one breaks
        // every match arm a consumer wrote against it.
        if ($inClassBody && $isEnum && $token->is(T_CASE) && $nextToken?->is(T_STRING)) {
            $end = $after;

            for ($j = $after; $j < $count; $j++) {
                if ($tokens[$j]->text === ';') {
                    break;
                }

                $end = $j;
            }

            $symbols[] = $class . '::' . $render($tokens, $after, $end);
            $i = $end;
        }

        if ($token->text === ';') {
            $modifiers = [];
        }
    }
}

$symbols = array_unique($symbols);
sort($symbols);

echo implode("\n", $symbols), "\n";
