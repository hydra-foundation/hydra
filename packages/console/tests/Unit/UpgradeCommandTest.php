<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\ArrayInput;
use Hydra\Console\Commands\UpgradeCommand;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\FakeOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpgradeCommand::class)]
final class UpgradeCommandTest extends TestCase
{
    private string $base;

    private FakeOutput $output;

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/hydra-upgrade-' . uniqid('', true);
        mkdir($this->base . '/vendor/composer', 0777, true);
        $this->output = new FakeOutput;
    }

    protected function tearDown(): void
    {
        @unlink($this->base . '/vendor/composer/installed.php');
        @rmdir($this->base . '/vendor/composer');
        @rmdir($this->base . '/vendor');
        @rmdir($this->base);
    }

    public function test_an_upgrade_reports_each_package_and_runs_the_steps_after_it(): void
    {
        $this->install(['hydrakit/core' => 'v0.9.7', 'hydrakit/http' => 'v0.9.7', 'psr/log' => '3.0.0']);

        $code = $this->upgrade(composer: function (): int {
            $this->install(['hydrakit/core' => 'v0.9.8', 'hydrakit/http' => 'v0.9.8', 'psr/log' => '3.0.2']);

            return 0;
        });

        $this->assertSame(ExitCode::Success, $code);
        $this->assertSame(['composer update hydrakit/*', 'bin/console migrate:run', 'bin/console route:cache:clear'], $this->ran);
        $this->assertSame(
            [['hydrakit/core', 'v0.9.7', 'v0.9.8'], ['hydrakit/http', 'v0.9.7', 'v0.9.8']],
            $this->output->tables()[0]['rows'],
        );
        $this->output->assertSuccess('Commit composer.lock');
    }

    public function test_nothing_newer_skips_the_steps_after_composer(): void
    {
        $this->install(['hydrakit/core' => 'v0.9.7']);

        $this->assertSame(ExitCode::Success, $this->upgrade());
        $this->assertSame(['composer update hydrakit/*'], $this->ran);
        $this->output->assertSuccess('v0.9.7');
    }

    public function test_a_failed_composer_run_migrates_nothing(): void
    {
        $this->install(['hydrakit/core' => 'v0.9.7']);

        $this->assertSame(ExitCode::Failure, $this->upgrade(composer: fn (): int => 2));
        $this->assertSame(['composer update hydrakit/*'], $this->ran);
        $this->output->assertError('nothing was migrated');
    }

    public function test_a_failed_step_is_named(): void
    {
        $this->install(['hydrakit/core' => 'v0.9.7']);

        $code = $this->upgrade(
            composer: function (): int {
                $this->install(['hydrakit/core' => 'v0.9.8']);

                return 0;
            },
            failing: 'migrate:run',
        );

        $this->assertSame(ExitCode::Failure, $code);
        $this->assertNotContains('bin/console route:cache:clear', $this->ran);
        $this->output->assertError('migrate:run failed');
    }

    public function test_a_checkout_linked_to_the_monorepo_is_refused_before_composer_runs(): void
    {
        $this->install(['hydrakit/core' => 'dev-main']);
        mkdir($this->base . '/vendor/hydrakit');
        symlink(sys_get_temp_dir(), $this->base . '/vendor/hydrakit/core');

        try {
            $this->assertSame(ExitCode::Failure, $this->upgrade());
        } finally {
            unlink($this->base . '/vendor/hydrakit/core');
            rmdir($this->base . '/vendor/hydrakit');
        }

        $this->assertSame([], $this->ran);
        $this->output->assertError('COMPOSER=composer.dev.json composer update');
    }

    public function test_a_directory_without_hydra_is_refused_before_composer_runs(): void
    {
        $this->assertSame(ExitCode::Failure, $this->upgrade());
        $this->assertSame([], $this->ran);
    }

    public function test_by_default_composer_is_run_as_a_process_in_the_checkout(): void
    {
        $this->install(['hydrakit/core' => 'v0.9.7']);
        $bin = $this->base . '/bin';
        mkdir($bin);
        file_put_contents($bin . '/composer', "#!/bin/sh\npwd > composer.ran\nexit 3\n");
        chmod($bin . '/composer', 0755);
        $path = (string) getenv('PATH');
        putenv("PATH={$bin}:{$path}");

        try {
            $code = (new UpgradeCommand($this->base))->execute(ArrayInput::withFlags([]), $this->output);
        } finally {
            putenv("PATH={$path}");
        }

        $this->assertSame(ExitCode::Failure, $code);
        $this->assertSame(realpath($this->base), trim((string) file_get_contents($this->base . '/composer.ran')));

        unlink($this->base . '/composer.ran');
        unlink($bin . '/composer');
        rmdir($bin);
    }

    /** @param array<string, string> $versions */
    private function install(array $versions): void
    {
        $packages = array_map(static fn (string $v): array => ['pretty_version' => $v], $versions);
        file_put_contents(
            $this->base . '/vendor/composer/installed.php',
            '<?php return ' . var_export(['root' => [], 'versions' => $packages], true) . ';',
        );
    }

    private function upgrade(?\Closure $composer = null, ?string $failing = null): ExitCode
    {
        $run = function (array $command, string $cwd) use ($composer, $failing): int {
            $this->assertSame($this->base, $cwd);

            if ($command[0] === 'composer') {
                $this->ran[] = implode(' ', $command);

                return $composer === null ? 0 : $composer();
            }

            $this->ran[] = implode(' ', array_slice($command, 1));

            return $command[2] === $failing ? 1 : 0;
        };

        return (new UpgradeCommand($this->base, $run))->execute(ArrayInput::withFlags([]), $this->output);
    }
}
