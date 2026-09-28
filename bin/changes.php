<?php

declare(strict_types=1);

/*
 * Release notes, written as the work lands rather than at tag time.
 *
 *   php bin/changes.php new <slug> [--section "The admin"] [--kind added]
 *   php bin/changes.php list
 *   php bin/changes.php release <x.y.z> --title "Faces" [--no-edit]
 *   php bin/changes.php check
 *   php bin/changes.php migrate ../wiki/public/docs/changelog.html
 *
 * Each change adds changes/unreleased/<slug>.md in its own commit. Before a
 * tag, `release` collects them into changes/<x.y.z>.md, opens it for the
 * intro, and deletes them. `check` parses everything; release.sh runs it.
 * `migrate` was the one-off that split the old hand-written changelog page
 * into release files.
 * --dir=<path> points at another changes/ directory (the tests use it).
 */

use Hydra\Tools\Changes\Collector;
use Hydra\Tools\Changes\Fragment;
use Hydra\Tools\Changes\InvalidChangeFile;
use Hydra\Tools\Changes\Migrator;
use Hydra\Tools\Changes\ReleaseFile;

require __DIR__ . '/../vendor/autoload.php';

const USAGE = <<<'TXT'
    usage: changes.php new <slug> [--section "…"] [--kind added]
           changes.php list
           changes.php release <x.y.z> --title "…" [--no-edit]
           changes.php check
           changes.php migrate <changelog.html>

    TXT;

function fail(string $message): never
{
    fwrite(STDERR, "changes.php: $message\n");

    exit(1);
}

/**
 * @param list<string> $argv
 * @return array{list<string>, array<string, string|true>}
 */
function arguments(array $argv): array
{
    $valued = ['dir', 'section', 'kind', 'title'];
    $flags = ['no-edit'];
    $positional = [];
    $options = [];

    for ($i = 0, $n = count($argv); $i < $n; $i++) {
        $arg = $argv[$i];
        if (!str_starts_with($arg, '--')) {
            $positional[] = $arg;
            continue;
        }

        [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
        if (in_array($name, $flags, true) && $value === null) {
            $options[$name] = true;
        } elseif (in_array($name, $valued, true)) {
            $value ??= $argv[++$i] ?? null;
            if ($value === null) {
                fail("--$name needs a value\n" . USAGE);
            }
            $options[$name] = $value;
        } else {
            fail("unknown option --$name\n" . USAGE);
        }
    }

    return [$positional, $options];
}

/**
 * Every fragment, in file-name order, which is the order the release lists
 * sections in. Each broken one is named before giving up.
 *
 * @return list<Fragment>
 */
function fragments(string $dir): array
{
    $fragments = [];
    $errors = [];
    foreach (glob("$dir/unreleased/*.md") ?: [] as $path) {
        try {
            $fragments[] = Fragment::fromFile($path);
        } catch (InvalidChangeFile $e) {
            $errors[] = $e->getMessage();
        }
    }
    if ($errors !== []) {
        fail("fix these first:\n  " . implode("\n  ", $errors));
    }

    return $fragments;
}

[$positional, $options] = arguments(array_slice($argv, 1));
$dir = rtrim((string) ($options['dir'] ?? dirname(__DIR__) . '/changes'), '/');
$command = $positional[0] ?? null;

switch ($command) {
    case 'new':
        $slug = $positional[1] ?? fail('which change? ' . USAGE);
        $kind = (string) ($options['kind'] ?? 'added');
        $section = isset($options['section']) ? (string) $options['section'] : null;
        $path = "$dir/unreleased/$slug.md";

        if (preg_match(Fragment::SLUG, $slug) !== 1) {
            fail("'$slug' is not a kebab-case slug, like file-uploads");
        }
        if (!in_array($kind, Fragment::KINDS, true)) {
            fail("'$kind' is not a kind; use one of " . implode(', ', Fragment::KINDS));
        }
        if (file_exists($path)) {
            fail("$path already exists; pick another slug, or edit that one");
        }
        if (!is_dir("$dir/unreleased") || file_put_contents($path, Fragment::template($section, $kind)) === false) {
            fail("could not write $path");
        }

        echo "Wrote $path. Fill in what changed; `check` refuses it until you do.\n";
        break;

    case 'list':
        $body = (new Collector(fragments($dir)))->body();
        echo $body === '' ? "No unreleased notes.\n" : $body;
        break;

    case 'release':
        $version = $positional[1] ?? null;
        $title = $options['title'] ?? null;
        if ($version === null || !is_string($title)) {
            fail("a release needs a version and a title\n" . USAGE);
        }
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            fail("'$version' is not a version; expected x.y.z");
        }

        $path = "$dir/$version.md";
        if (file_exists($path)) {
            fail("$path already exists; a released file is corrected in a commit of its own, not regenerated");
        }

        $fragments = fragments($dir);
        if ($fragments === []) {
            fail("nothing to release: $dir/unreleased/ holds no notes. Add one with: php bin/changes.php new <slug>");
        }

        $collector = new Collector($fragments);
        try {
            $release = new ReleaseFile($version, $title, date('Y-m-d'), $collector->security(), ReleaseFile::PLACEHOLDER_INTRO . "\n\n" . $collector->body());
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }

        if (file_put_contents($path, $release->render()) === false) {
            fail("could not write $path");
        }
        // Git still has them; the release file is now the only copy that matters.
        foreach ($fragments as $fragment) {
            unlink("$dir/unreleased/$fragment->slug.md");
        }
        echo "Wrote $path from " . count($fragments) . ' note' . (count($fragments) === 1 ? '' : 's') . ".\n";

        if (isset($options['no-edit'])) {
            echo "Now write the intro in $path; `check` refuses it until you do.\n";
            break;
        }

        $editor = getenv('VISUAL') ?: getenv('EDITOR') ?: 'vi';
        $process = proc_open("$editor " . escapeshellarg($path), [STDIN, STDOUT, STDERR], $pipes);
        $status = is_resource($process) ? proc_close($process) : 1;

        try {
            ReleaseFile::fromFile($path);
        } catch (InvalidChangeFile $e) {
            fwrite(STDERR, ($status === 0 ? '' : "$editor exited $status. ") . "Still to do: {$e->getMessage()}\n");
        }
        break;

    case 'check':
        $errors = [];
        $counts = ['fragment' => 0, 'release file' => 0];
        foreach (glob("$dir/unreleased/*.md") ?: [] as $path) {
            try {
                Fragment::fromFile($path);
                $counts['fragment']++;
            } catch (InvalidChangeFile $e) {
                $errors[] = $e->getMessage();
            }
        }
        foreach (glob("$dir/*.md") ?: [] as $path) {
            try {
                ReleaseFile::fromFile($path);
                $counts['release file']++;
            } catch (InvalidChangeFile $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            fail(count($errors) . ' file' . (count($errors) === 1 ? '' : 's') . " to fix:\n  " . implode("\n  ", $errors));
        }

        echo implode(' and ', array_map(
            static fn (string $noun, int $n): string => "$n $noun" . ($n === 1 ? '' : 's'),
            array_keys($counts),
            $counts,
        )) . " parse.\n";
        break;

    case 'migrate':
        $page = $positional[1] ?? fail("which page?\n" . USAGE);
        $html = is_file($page) ? file_get_contents($page) : false;
        if ($html === false) {
            fail("cannot read $page");
        }

        try {
            $releases = Migrator::split($html);
        } catch (UnexpectedValueException | InvalidArgumentException $e) {
            fail("$page: {$e->getMessage()}");
        }

        // All or nothing: a half-migrated directory is harder to reason
        // about than one that was refused.
        $taken = array_filter(array_map(static fn (ReleaseFile $r): string => "$dir/$r->version.md", $releases), 'file_exists');
        if ($taken !== []) {
            fail("refusing to overwrite:\n  " . implode("\n  ", $taken));
        }

        foreach ($releases as $release) {
            if (file_put_contents("$dir/$release->version.md", $release->render()) === false) {
                fail("could not write $dir/$release->version.md");
            }
        }

        echo 'Wrote ' . count($releases) . " release files, {$releases[0]->version} back to " . $releases[count($releases) - 1]->version . ".\n";
        break;

    default:
        fail(($command === null ? '' : "unknown command '$command'\n") . USAGE);
}
