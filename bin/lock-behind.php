<?php

declare(strict_types=1);

/*
 * Print every hydrakit/* package a composer.lock holds at anything other than
 * the given tag, one "name version" per line. Exits 0 either way; the caller
 * decides what a stale package costs.
 *
 *   php bin/lock-behind.php app/composer.lock v0.9.6
 *
 * `composer update "hydrakit/*"` does not fail when one package cannot reach
 * the new tag. It keeps that one where it was and moves the rest, and the lock
 * validates. In 0.9.6 core began requiring ext-sodium, the machine running the
 * release had none, and core stayed at 0.9.5 under twenty-one packages at
 * 0.9.6 that needed its new interfaces.
 */

if ($argc !== 3) {
    fwrite(STDERR, "usage: lock-behind.php <composer.lock> <tag>\n");

    exit(1);
}

$json = @file_get_contents($argv[1]);
$lock = $json === false ? null : json_decode($json, true);

if (!is_array($lock) || !is_array($lock['packages'] ?? null)) {
    fwrite(STDERR, "lock-behind.php: cannot read a lock from {$argv[1]}\n");

    exit(1);
}

$tag = $argv[2];
$behind = [];

foreach ([...$lock['packages'], ...($lock['packages-dev'] ?? [])] as $package) {
    $name = (string) ($package['name'] ?? '');
    $version = (string) ($package['version'] ?? '');

    if (str_starts_with($name, 'hydrakit/') && $version !== $tag) {
        $behind[] = "{$name} {$version}";
    }
}

echo $behind === [] ? '' : implode("\n", $behind) . "\n";
