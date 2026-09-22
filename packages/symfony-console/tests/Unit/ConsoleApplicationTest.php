<?php

declare(strict_types=1);

namespace Hydra\SymfonyConsole\Tests\Unit;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\SymfonyConsole\ConsoleApplication;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The entrypoint: what `bin/console` is, so that a console script names no
 * third-party class of its own.
 */
#[CoversClass(ConsoleApplication::class)]
final class ConsoleApplicationTest extends TestCase
{
    public function test_an_added_command_is_registered_under_its_attribute_name(): void
    {
        $console = (new ConsoleApplication)->add(new EagerCommand);

        $this->assertTrue($console->symfony()->has('demo:eager'));
    }

    public function test_running_one_command_does_not_build_the_others(): void
    {
        // The guarantee worth having, and the reason the name lives on an
        // attribute: `migrate:run` must not construct the generators, because
        // theirs is the constructor that opens a database connection.
        //
        // Note this is narrower than "never built until named". Symfony's
        // Application::has() resolves through the loader and so does build,
        // which is why the assertion below is about running rather than asking.
        $built = 0;

        $console = (new ConsoleApplication)
            ->add(new EagerCommand)
            ->addLazy([
                LazyCommand::class => function () use (&$built): LazyCommand {
                    $built++;

                    return new LazyCommand;
                },
            ]);
        $symfony = $console->symfony();
        $symfony->setAutoExit(false);

        $symfony->run(new ArrayInput(['command' => 'demo:eager']), new BufferedOutput);

        $this->assertSame(0, $built);
    }

    public function test_a_lazy_command_is_built_when_it_is_run(): void
    {
        $built = 0;

        $console = (new ConsoleApplication)->addLazy([
            LazyCommand::class => function () use (&$built): LazyCommand {
                $built++;

                return new LazyCommand;
            },
        ]);
        $symfony = $console->symfony();
        $symfony->setAutoExit(false);

        $output = new BufferedOutput;
        $status = $symfony->run(new ArrayInput(['command' => 'demo:lazy']), $output);

        $this->assertSame(0, $status);
        $this->assertSame(1, $built);
        $this->assertStringContainsString('lazily', $output->fetch());
    }

    public function test_it_runs_an_eager_command_end_to_end(): void
    {
        $console = (new ConsoleApplication)->add(new EagerCommand);
        $symfony = $console->symfony();
        $symfony->setAutoExit(false);

        $output = new BufferedOutput;
        $status = $symfony->run(new ArrayInput(['command' => 'demo:eager']), $output);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('eagerly', $output->fetch());
    }

    public function test_the_application_carries_the_name_and_version_it_was_given(): void
    {
        $console = new ConsoleApplication('Hydra', '0.7.0');

        $this->assertSame('Hydra', $console->symfony()->getName());
        $this->assertSame('0.7.0', $console->symfony()->getVersion());
    }
}

#[AsCommand(name: 'demo:eager', description: 'Built up front')]
final class EagerCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $output->write('ran eagerly');

        return ExitCode::Success;
    }
}

#[AsCommand(name: 'demo:lazy', description: 'Built when named')]
final class LazyCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $output->write('ran lazily');

        return ExitCode::Success;
    }
}
