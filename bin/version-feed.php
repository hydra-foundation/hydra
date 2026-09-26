<?php

declare(strict_types=1);

/*
 * Merge one release into the version.json the admin's update check reads.
 *
 *   php bin/version-feed.php ../hydra-frontend/public/version.json 0.9.8 [--security]
 *
 * Merged rather than written fresh, since the security list is history: an
 * install three patches behind still has to learn it skipped a fix.
 */

const NOTES = 'https://hydra.williamhleucka.com/docs/changelog.html';
const RELEASE = '/^\d+\.\d+\.\d+$/';

$args = array_slice($argv, 1);
$security = in_array('--security', $args, true);
$args = array_values(array_diff($args, ['--security']));

if (count($args) !== 2 || preg_match(RELEASE, ltrim($args[1], 'v')) !== 1) {
    fwrite(STDERR, "usage: version-feed.php <version.json> <x.y.z> [--security]\n");

    exit(1);
}

[$path, $version] = [$args[0], ltrim($args[1], 'v')];
$series = implode('.', array_slice(explode('.', $version), 0, 2));
$feed = ['latest' => $version, 'series' => [], 'security' => []];

if (is_file($path)) {
    $existing = json_decode((string) file_get_contents($path), true);

    if (!is_array($existing) || !is_string($existing['latest'] ?? null)) {
        fwrite(STDERR, "version-feed.php: {$path} is not a feed this script wrote; not overwriting it\n");

        exit(1);
    }

    $feed = $existing + $feed;
}

$max = static fn (?string $a, string $b): string => $a !== null && version_compare($a, $b, '>') ? $a : $b;

$feed['latest'] = $max($feed['latest'], $version);
$feed['series'][$series] = $max($feed['series'][$series] ?? null, $version);
uksort($feed['series'], static fn (string $a, string $b): int => version_compare($b . '.0', $a . '.0'));

if ($security) {
    $feed['security'][] = $version;
}

$feed['security'] = array_values(array_unique($feed['security']));
usort($feed['security'], static fn (string $a, string $b): int => version_compare($b, $a));
$feed['notes'] = NOTES;

$json = json_encode(
    ['latest' => $feed['latest'], 'series' => $feed['series'], 'security' => $feed['security'], 'notes' => $feed['notes']],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . "\n";

$tmp = $path . '.tmp';

if (file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
    fwrite(STDERR, "version-feed.php: could not write {$path}\n");

    exit(1);
}
