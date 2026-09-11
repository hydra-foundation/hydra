<?php

declare(strict_types=1);

/**
 * PSR-12, minus the two rules that disagree with how this codebase is written.
 * The fixer exists to catch drift, not to relitigate settled style: a ruleset
 * that rewrites half the tree on adoption teaches everyone to ignore it.
 */
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/packages')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,

        // Hydra writes `new AuthConfig`, not `new AuthConfig()`.
        'new_with_parentheses' => false,

        // ... and keeps an empty body on one line: `public function x(): void {}`.
        'single_line_empty_body' => true,
    ])
    ->setFinder($finder);
