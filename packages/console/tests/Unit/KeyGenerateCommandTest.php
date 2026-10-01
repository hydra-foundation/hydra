<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\Commands\KeyGenerateCommand;
use Hydra\Console\ExitCode;
use Hydra\Console\ArrayInput;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * key:generate against a real temporary .env, weighted towards the cases where
 * an APP_KEY already exists: overwriting one silently would invalidate
 * everything sealed with the old key.
 */
#[CoversClass(KeyGenerateCommand::class)]
final class KeyGenerateCommandTest extends TestCase
{
    private string $envPath;

    private FakeOutput $output;

    protected function setUp(): void
    {
        $this->envPath = sys_get_temp_dir() . '/hydra-keygen-' . uniqid('', true) . '.env';
        $this->output = new FakeOutput;
    }

    protected function tearDown(): void
    {
        if (is_file($this->envPath)) {
            unlink($this->envPath);
        }
    }

    /** @param list<string> $flags */
    private function generate(array $flags = []): ExitCode
    {
        return (new KeyGenerateCommand($this->envPath))
            ->execute(ArrayInput::withFlags($flags), $this->output);
    }

    /** APP_KEY as the application would read it, comment and quotes stripped. */
    private function key(): string
    {
        preg_match('/^APP_KEY=(.*)$/m', file_get_contents($this->envPath), $m);

        return Environment::parseValue(trim($m[1] ?? ''));
    }

    public function test_writes_a_key_when_app_key_is_empty(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Hydra\nAPP_KEY=\n");

        $this->assertSame(ExitCode::Success, $this->generate());

        // A 256-bit key is 64 hex chars; the rest of the file is preserved.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->key());
        $this->assertStringContainsString('APP_NAME=Hydra', file_get_contents($this->envPath));
    }

    public function test_refuses_to_overwrite_an_existing_key_without_force(): void
    {
        file_put_contents($this->envPath, "APP_KEY=existing\n");

        $this->assertSame(ExitCode::Failure, $this->generate());
        $this->output->assertError('already set');

        // The existing key is left untouched.
        $this->assertSame('existing', $this->key());
    }

    public function test_overwrites_an_existing_key_with_force(): void
    {
        file_put_contents($this->envPath, "APP_KEY=existing\n");

        $this->assertSame(ExitCode::Success, $this->generate(['force']));
        $this->assertNotSame('existing', $this->key());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->key());
    }

    public function test_appends_app_key_when_the_line_is_absent(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Hydra\n");

        $this->assertSame(ExitCode::Success, $this->generate());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->key());
        $this->assertStringContainsString('APP_NAME=Hydra', file_get_contents($this->envPath));
    }

    public function test_fails_when_env_file_is_missing(): void
    {
        // setUp() never created the file.
        $this->assertSame(ExitCode::Failure, $this->generate());
        $this->output->assertError('No .env');
    }

    public function test_an_inline_comment_is_not_a_key(): void
    {
        // The skeleton's .env.example line, copied to .env on a fresh install.
        file_put_contents($this->envPath, "APP_KEY=                          # Generate with: php bin/console key:generate\n");

        $this->assertSame(ExitCode::Success, $this->generate());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->key());
    }

    public function test_writing_the_key_keeps_the_lines_comment(): void
    {
        file_put_contents($this->envPath, "APP_KEY=   # Generate with: php bin/console key:generate\nAPP_DEBUG=false\n");

        $this->generate();

        $this->assertMatchesRegularExpression(
            '/^APP_KEY=[0-9a-f]{64}   # Generate with: php bin\/console key:generate$/m',
            file_get_contents($this->envPath),
        );
        $this->assertStringContainsString("\nAPP_DEBUG=false\n", file_get_contents($this->envPath));
    }

    public function test_a_quoted_key_is_a_key(): void
    {
        file_put_contents($this->envPath, "APP_KEY=\"existing\"\n");

        $this->assertSame(ExitCode::Failure, $this->generate());
        $this->output->assertError('already set');
        $this->assertSame('existing', $this->key());
    }

    public function test_a_comment_with_no_space_before_it_stays_a_comment(): void
    {
        // Glued straight on, `#bare` would read as part of the new key.
        file_put_contents($this->envPath, "APP_KEY=#bare\n");

        $this->assertSame(ExitCode::Success, $this->generate());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->key());
        $this->assertMatchesRegularExpression('/^APP_KEY=[0-9a-f]{64} #bare$/m', file_get_contents($this->envPath));
    }
}
