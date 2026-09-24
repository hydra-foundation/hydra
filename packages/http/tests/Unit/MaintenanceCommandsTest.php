<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Http\Console\DownCommand;
use Hydra\Http\Console\UpCommand;
use Hydra\Http\Maintenance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(DownCommand::class)]
#[CoversClass(UpCommand::class)]
final class MaintenanceCommandsTest extends CommandContractTestCase
{
    private string $path;

    private Maintenance $maintenance;

    public static function commands(): iterable
    {
        $maintenance = new Maintenance(sys_get_temp_dir() . '/hydra-declared-maintenance.json');

        yield 'down' => new DownCommand($maintenance);
        yield 'up' => new UpCommand($maintenance);
    }

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/hydra-maintenance-' . bin2hex(random_bytes(4)) . '.json';
        $this->maintenance = new Maintenance($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_down_with_a_message_and_a_retry(): void
    {
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, $this->down(['message' => 'Back at noon.', 'retry' => '600'], $output));

        $output->assertSuccess('The application is down for maintenance.');
        $output->assertSaid('/up still answers');
        $this->assertSame('Back at noon.', $this->maintenance->current()['message'] ?? null);
        $this->assertSame(600, $this->maintenance->current()['retry'] ?? null);
    }

    public function test_down_alone_uses_the_default_message_and_no_retry(): void
    {
        $this->down([], new FakeOutput);

        $down = $this->maintenance->current();
        $this->assertNotNull($down);
        $this->assertSame(Maintenance::DEFAULT_MESSAGE, $down['message']);
        $this->assertNull($down['retry']);
    }

    public function test_a_blank_message_falls_back_to_the_default(): void
    {
        $this->down(['message' => '   '], new FakeOutput);

        $this->assertSame(Maintenance::DEFAULT_MESSAGE, $this->maintenance->current()['message'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function badRetries(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'words' => ['soon'];
        yield 'fraction' => ['1.5'];
    }

    public function test_a_retry_of_one_second_is_the_least_accepted(): void
    {
        $this->assertSame(ExitCode::Success, $this->down(['retry' => '1'], new FakeOutput));
        $this->assertSame(1, $this->maintenance->current()['retry'] ?? null);
    }

    #[DataProvider('badRetries')]
    public function test_a_retry_that_is_not_whole_seconds_is_refused_and_nothing_changes(string $retry): void
    {
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Failure, $this->down(['retry' => $retry], $output));

        $output->assertError('--retry takes a whole number of seconds');
        $this->assertNull($this->maintenance->current());
    }

    public function test_up_brings_it_back_and_already_up_is_success(): void
    {
        $this->maintenance->down();
        $first = new FakeOutput;
        $second = new FakeOutput;
        $up = new UpCommand($this->maintenance);

        $this->assertSame(ExitCode::Success, $up->execute(new ArrayInput, $first));
        $this->assertSame(ExitCode::Success, $up->execute(new ArrayInput, $second));

        $first->assertSuccess('The application is up.');
        $second->assertSuccess('The application was already up.');
        $this->assertNull($this->maintenance->current());
    }

    /** @param array<string, string> $options */
    private function down(array $options, FakeOutput $output): ExitCode
    {
        $command = new DownCommand($this->maintenance);

        return $command->execute(ArrayInput::forCommand($command, [], $options), $output);
    }
}
