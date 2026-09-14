<?php

/*
 * Report line coverage per package from a clover report, and fail when a
 * package sits below the floor recorded for it in coverage.json.
 *
 * One number for the tree hides the packages that need the work: http and
 * admin are two thirds of the source and carry the average on their own. A
 * floor per package is the only shape in which a thin package stays visible.
 *
 *   vendor/bin/phpunit --coverage-clover build/coverage.xml
 *   php bin/coverage.php build/coverage.xml
 *   php bin/coverage.php --write build/coverage.xml   # record today as the floor
 */

$args = array_slice($argv, 1);
$write = in_array('--write', $args, true);
$args = array_values(array_filter($args, static fn (string $a) => $a !== '--write'));
$report = $args[0] ?? 'build/coverage.xml';

$root = dirname(__DIR__);
$floorFile = $root . '/coverage.json';

if (!is_file($report)) {
    fwrite(STDERR, "no clover report at {$report}\n");
    fwrite(STDERR, "run: vendor/bin/phpunit --coverage-clover {$report}\n");
    exit(1);
}

$xml = simplexml_load_file($report);

if ($xml === false) {
    fwrite(STDERR, "{$report} is not readable as XML\n");
    exit(1);
}

/** @var array<string, array{covered: int, total: int}> $packages */
$packages = [];

foreach ($xml->xpath('//file') as $file) {
    $path = (string) $file['name'];

    if (!preg_match('#/packages/([^/]+)/src/#', $path, $match)) {
        continue;
    }

    $package = $match[1];
    $packages[$package] ??= ['covered' => 0, 'total' => 0];

    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }

        $packages[$package]['total']++;
        $packages[$package]['covered'] += ((int) $line['count'] > 0) ? 1 : 0;
    }
}

if ($packages === []) {
    fwrite(STDERR, "{$report} names no packages/*/src file — was it generated for this tree?\n");
    exit(1);
}

ksort($packages);

$percent = static function (array $counts): float {
    return $counts['total'] === 0 ? 100.0 : round($counts['covered'] / $counts['total'] * 100, 2);
};

if ($write) {
    $floors = [];

    foreach ($packages as $package => $counts) {
        $floors[$package] = floor($percent($counts) * 10) / 10;
    }

    file_put_contents(
        $floorFile,
        json_encode($floors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );

    echo "wrote floors for " . count($floors) . " packages to coverage.json\n";
    exit(0);
}

$floors = is_file($floorFile)
    ? json_decode((string) file_get_contents($floorFile), true)
    : [];

if (!is_array($floors)) {
    fwrite(STDERR, "coverage.json is not a JSON object of package => floor\n");
    exit(1);
}

$width = max(array_map('strlen', array_keys($packages)));
$failed = [];
$totals = ['covered' => 0, 'total' => 0];

foreach ($packages as $package => $counts) {
    $totals['covered'] += $counts['covered'];
    $totals['total'] += $counts['total'];

    $actual = $percent($counts);
    $floor = isset($floors[$package]) ? (float) $floors[$package] : null;
    $below = $floor !== null && $actual + 0.005 < $floor;

    if ($below) {
        $failed[] = sprintf('%s %.2f%% < %.1f%%', $package, $actual, $floor);
    }

    printf(
        "  %-{$width}s  %6.2f%%  %5d/%-5d  %s\n",
        $package,
        $actual,
        $counts['covered'],
        $counts['total'],
        $floor === null ? 'no floor' : ($below ? sprintf('FAIL floor %.1f%%', $floor) : sprintf('floor %.1f%%', $floor)),
    );
}

printf("  %-{$width}s  %6.2f%%  %5d/%-5d\n", 'total', $percent($totals), $totals['covered'], $totals['total']);

$unknown = array_diff(array_keys($floors), array_keys($packages));

if ($unknown !== []) {
    // A floor whose package is gone is a floor nothing enforces, and the file
    // is hand-edited often enough for one to survive a rename unnoticed.
    fwrite(STDERR, "\ncoverage.json names packages the report does not: " . implode(', ', $unknown) . "\n");
    exit(1);
}

$missing = array_diff(array_keys($packages), array_keys($floors));

if ($missing !== []) {
    fwrite(STDERR, "\nno floor recorded for: " . implode(', ', $missing) . "\n");
    exit(1);
}

if ($failed !== []) {
    fwrite(STDERR, "\ncoverage fell below the floor:\n  " . implode("\n  ", $failed) . "\n");
    exit(1);
}

echo "\nevery package is at or above its floor\n";
