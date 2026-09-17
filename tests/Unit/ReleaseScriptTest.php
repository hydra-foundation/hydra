<?php

declare(strict_types=1);

namespace Hydra\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * bin/release.sh itself, over a pair of throwaway repositories.
 *
 * {@see ApiSurfaceTest} covers the two tools the gate calls; this covers the
 * shell between them, which is where the decision actually lives: a patch
 * carrying a removal has to be refused and --minor has to let the same one
 * through. That is the promise ^0.5 makes to a consumer, and 0.3.4 is what it
 * costs to get it wrong — six renames shipped as a patch and pulled off
 * Packagist an hour later.
 *
 * Nothing real is touched. HYDRA_DIR points the script at a fixture tree whose
 * origins are bare repositories in the same temp directory, and every case runs
 * without --push, so the script stops at the plan. The one thing stubbed beyond
 * that is the environment the preflight probes — a php that answers the two
 * Redis questions and a phpunit that passes — because what is under test is the
 * gate and not this machine.
 */
#[CoversNothing]
final class ReleaseScriptTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../bin/release.sh';

    /** The package file whose surface the gate compares across the tag. */
    private const BEFORE = '<?php namespace Acme; final class Thing { public function kept(): void {} public function dropped(): void {} }';

    private const WITHOUT_DROPPED = '<?php namespace Acme; final class Thing { public function kept(): void {} }';

    private const WITH_AN_ADDITION = '<?php namespace Acme; final class Thing { public function kept(): void {} public function dropped(): void {} public function added(): void {} }';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-release-' . uniqid('', true);
        $this->build();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->remove($this->dir);
        }
    }

    public function test_a_patch_that_removes_a_symbol_is_refused(): void
    {
        $this->commit(self::WITHOUT_DROPPED);

        [$status, $output] = $this->release('0.5.1');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('Removed since v0.5.0:', $output);
        $this->assertStringContainsString('Acme\Thing->dropped(): void', $output);
        $this->assertStringContainsString('Refusing to release:', $output);
        // The refusal names the release that would be correct, because a gate
        // that only says no is one you argue with rather than act on.
        $this->assertStringContainsString('release it as 0.6.0 --minor', $output);
    }

    public function test_minor_lets_the_same_removal_through(): void
    {
        $this->commit(self::WITHOUT_DROPPED);

        [$status, $output] = $this->release('0.6.0', '--minor');

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('the release notes owe consumers an upgrade path', $output);
        $this->assertStringContainsString('Acme\Thing->dropped(): void', $output);
        $this->assertStringContainsString('All clean.', $output);
    }

    public function test_a_release_that_only_adds_stays_a_patch(): void
    {
        // The other half of the gate: one that cried break on every release
        // would be handed --minor by reflex, which costs the same as not
        // having one.
        $this->commit(self::WITH_AN_ADDITION);

        [$status, $output] = $this->release('0.5.1');

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('All clean.', $output);
        $this->assertStringNotContainsString('Refusing to release', $output);
    }

    public function test_a_dry_run_leaves_both_repositories_exactly_as_it_found_them(): void
    {
        $this->commit(self::WITH_AN_ADDITION);
        $before = [$this->head('hydra'), $this->head('app'), $this->tags('hydra'), $this->tags('app')];

        [$status, $output] = $this->release('0.5.1');

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('Dry run — nothing written.', $output);
        $this->assertSame(
            $before,
            [$this->head('hydra'), $this->head('app'), $this->tags('hydra'), $this->tags('app')],
            'A dry run moved a HEAD or wrote a tag.',
        );
    }

    public function test_a_dirty_working_tree_is_refused(): void
    {
        file_put_contents($this->dir . '/hydra/packages/thing/src/Stray.php', '<?php namespace Acme; final class Stray {}');

        [$status, $output] = $this->release('0.5.1');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('working tree is dirty', $output);
    }

    public function test_a_failing_suite_stops_the_release(): void
    {
        $this->stubPhpunit(exit: 1);

        [$status, $output] = $this->release('0.5.1');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('the test suite fails', $output);
    }

    public function test_it_refuses_when_nothing_can_run_the_redis_tests(): void
    {
        // Without the probes answered, neither route is available: this host's
        // php is asked and says no, and the app stack is a temp directory with
        // no compose file and a docker that refuses anyway. The suite still
        // runs; what must not happen is a release going out on a green run
        // that never touched the store the rate limiter rests on.
        $this->stubEnvironment(redis: false);

        [$status, $output] = $this->release('0.5.1');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('nowhere to run the Redis tests', $output);
        $this->assertStringContainsString('this machine: no ext-redis', $output);
        $this->assertStringContainsString('app stack: not reachable', $output);
    }

    public function test_the_suite_is_told_to_require_redis_once_a_route_is_found(): void
    {
        // The flag is the whole point of the check: with a route available the
        // twenty tests that cover the store have to run rather than skip. The
        // stub phpunit reports the variable it was handed.
        $this->commit(self::WITH_AN_ADDITION);
        $this->stubPhpunit(exit: 0, report: true);

        [$status, $output] = $this->release('0.5.1');

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('Running the suite (this machine) ...', $output);
        $this->assertSame(
            "1\n",
            file_get_contents($this->dir . '/redis-required'),
            'The suite ran without REDIS_REQUIRED even though a Redis was reachable.',
        );
    }

    public function test_a_minor_inside_the_current_series_is_refused(): void
    {
        [$status, $output] = $this->release('0.5.1', '--minor');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('still in the 0.5 series', $output);
    }

    public function test_leaving_the_series_without_minor_is_refused(): void
    {
        // ^0.5 will not accept 0.6.0, so the sibling constraints have to be
        // rewritten, and --minor is the flag that says so out loud.
        [$status, $output] = $this->release('0.6.0');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('leaves the 0.5 series', $output);
    }

    public function test_a_version_that_is_not_a_version_is_refused(): void
    {
        [$status, $output] = $this->release('0.5');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString("version must look like 0.3.1, got '0.5'", $output);
    }

    /** @return array{int, string} */
    private function release(string ...$args): array
    {
        exec(
            sprintf(
                'env HYDRA_DIR=%s PATH=%s %s %s 2>&1',
                escapeshellarg($this->dir),
                escapeshellarg($this->dir . '/stub:' . getenv('PATH')),
                escapeshellarg(self::SCRIPT),
                implode(' ', array_map(escapeshellarg(...), $args)),
            ),
            $output,
            $status,
        );

        return [$status, implode("\n", $output) . "\n"];
    }

    /**
     * Two repositories with an origin apiece, one package with a surface, and a
     * v0.5.0 to compare against.
     */
    private function build(): void
    {
        mkdir($this->dir . '/hydra/packages/thing/src', 0o775, true);
        mkdir($this->dir . '/hydra/bin', 0o775, true);
        mkdir($this->dir . '/hydra/vendor/bin', 0o775, true);
        mkdir($this->dir . '/app', 0o775, true);
        mkdir($this->dir . '/stub', 0o775, true);

        // The real tools: the gate is only as good as what it calls, and a
        // fixture copy of them would be a second implementation to keep true.
        foreach (['api-surface.php', 'api-diff.php'] as $tool) {
            copy(__DIR__ . '/../../bin/' . $tool, $this->dir . '/hydra/bin/' . $tool);
        }

        // The series the script reads back out of the monorepo rather than
        // assuming, so the fixture has to declare one.
        file_put_contents(
            $this->dir . '/hydra/composer.json',
            json_encode(['extra' => ['branch-alias' => ['dev-main' => '0.5.x-dev']]], JSON_PRETTY_PRINT),
        );
        file_put_contents($this->dir . '/app/composer.json', json_encode(['name' => 'acme/app'], JSON_PRETTY_PRINT));
        file_put_contents($this->dir . '/hydra/packages/thing/src/Thing.php', self::BEFORE);
        // As a real checkout ignores it, and for a reason this fixture needs:
        // the stub phpunit lives under vendor/ and some cases rewrite it after
        // the repository exists, which would otherwise show up as a dirty tree
        // and refuse the release for something the case is not about.
        file_put_contents($this->dir . '/hydra/.gitignore', "vendor/\n");

        $this->stubPhpunit(exit: 0);
        $this->stubEnvironment(redis: true);

        foreach (['hydra', 'app'] as $repo) {
            $bare = $this->dir . '/origin/' . $repo . '.git';
            mkdir($bare, 0o775, true);
            $this->shell("git init --quiet --bare {$this->arg($bare)}");

            $this->git($repo, 'init --quiet -b main');
            $this->git($repo, 'add -A');
            $this->git($repo, 'commit --quiet -m first');
            $this->git($repo, 'remote add origin ' . $this->arg($bare));
            $this->git($repo, 'push --quiet -u origin main');
        }

        $this->git('hydra', 'tag v0.5.0');
        $this->git('hydra', 'push --quiet origin v0.5.0');
    }

    /** Rewrite the package's one class and commit, so the tree stays clean. */
    private function commit(string $source): void
    {
        file_put_contents($this->dir . '/hydra/packages/thing/src/Thing.php', $source);
        $this->git('hydra', 'add -A');
        $this->git('hydra', 'commit --quiet -m change');
    }

    /**
     * A phpunit that does not exist, standing in for one that does. With
     * $report it writes down whether REDIS_REQUIRED reached it, which is the
     * only observable difference between the two routes the preflight picks.
     */
    private function stubPhpunit(int $exit, bool $report = false): void
    {
        $line = $report
            ? 'printf \'%s\n\' "${REDIS_REQUIRED:-unset}" > ' . $this->arg($this->dir . '/redis-required') . "\n"
            : '';

        $this->script($this->dir . '/hydra/vendor/bin/phpunit', "#!/bin/sh\n{$line}exit {$exit}\n");
    }

    /**
     * The two probes the preflight makes before it sets REDIS_REQUIRED, pinned
     * either way. Everything that is not one of them is handed to the real php,
     * because the script runs api-surface.php through the same name.
     */
    private function stubEnvironment(bool $redis): void
    {
        $answer = $redis ? 0 : 1;

        $this->script($this->dir . '/stub/php', <<<SH
            #!/bin/sh
            case "\$2" in
                *extension_loaded*) exit {$answer} ;;
                *Redis*) exit {$answer} ;;
            esac
            exec {$this->arg(PHP_BINARY)} "\$@"
            SH);

        // Only in the way when the host route is meant to be closed: with it
        // open the container is never probed.
        $this->script($this->dir . '/stub/docker', "#!/bin/sh\nexit 1\n");
    }

    private function script(string $path, string $body): void
    {
        file_put_contents($path, rtrim($body) . "\n");
        chmod($path, 0o775);
    }

    private function git(string $repo, string $command): void
    {
        // Named here rather than left to the machine, in both directions. A
        // checkout with no user.email cannot commit, and CI is exactly that
        // machine; a developer with commit.gpgsign set globally — which is the
        // machine this was written on — cannot commit or tag in CI either,
        // because there is no key there to sign with.
        $this->shell(sprintf(
            'git -C %s -c user.email=release@example.test -c user.name=Release'
            . ' -c commit.gpgsign=false -c tag.gpgsign=false %s',
            $this->arg($this->dir . '/' . $repo),
            $command,
        ));
    }

    private function head(string $repo): string
    {
        return $this->shell('git -C ' . $this->arg($this->dir . '/' . $repo) . ' rev-parse HEAD');
    }

    private function tags(string $repo): string
    {
        return $this->shell('git -C ' . $this->arg($this->dir . '/' . $repo) . ' tag --list');
    }

    private function shell(string $command): string
    {
        exec($command . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, "fixture command failed: {$command}\n" . implode("\n", $output));

        return implode("\n", $output);
    }

    private function arg(string $value): string
    {
        return escapeshellarg($value);
    }

    private function remove(string $path): void
    {
        exec('rm -rf ' . $this->arg($path));
    }
}
