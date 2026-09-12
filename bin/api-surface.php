<?php

/*
 * Print the public surface under a packages/ directory, one symbol per line:
 * a class-like name, Class::method for a public method, Class::CONST for a
 * public constant. Properties are not walked — constructor promotion makes
 * them ambiguous to read this way, and nothing has been broken by one yet.
 *
 * bin/release.sh diffs the output at the previous tag against the output here.
 * A symbol that disappears is a break, and ^0.3 promises consumers there are
 * none inside the series.
 */

$root = $argv[1] ?? null;

if ($root === null || !is_dir($root)) {
    fwrite(STDERR, "usage: api-surface.php <packages-dir>\n");
    exit(1);
}

$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
    '#/src/.+\.php$#'
);

$symbols = [];

foreach ($files as $file) {
    $tokens = PhpToken::tokenize(file_get_contents($file->getPathname()));
    $tokens = array_values(array_filter(
        $tokens,
        static fn (PhpToken $t) => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));

    $namespace = '';
    $class = null;
    $modifiers = [];

    foreach ($tokens as $i => $token) {
        $next = $tokens[$i + 1] ?? null;
        $previous = $tokens[$i - 1] ?? null;

        if ($token->is(T_NAMESPACE) && $next?->is([T_NAME_QUALIFIED, T_STRING])) {
            $namespace = $next->text;
            continue;
        }

        // ::class is a T_CLASS too, and `new class` has no name to record.
        if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])
            && !($previous?->is(T_DOUBLE_COLON) ?? false)
            && $next?->is(T_STRING)
        ) {
            $class = $namespace . '\\' . $next->text;
            $symbols[] = $class;
            $modifiers = [];
            continue;
        }

        if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY])) {
            $modifiers[] = $token->id;
            continue;
        }

        if ($class !== null && $token->is([T_FUNCTION, T_CONST]) && $next?->is(T_STRING)) {
            // An interface method and a bare `const` declare no visibility and
            // are public; anything explicitly hidden is not surface.
            if (array_intersect($modifiers, [T_PROTECTED, T_PRIVATE]) === []) {
                $symbols[] = $class . '::' . $next->text;
            }
        }

        if ($token->text === ';' || $token->text === '{' || $token->text === '}') {
            $modifiers = [];
        }
    }
}

$symbols = array_unique($symbols);
sort($symbols);

echo implode("\n", $symbols), "\n";
