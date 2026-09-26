<?php

declare(strict_types=1);

namespace Hydra\SymfonyConsole;

use Hydra\Console\CommandScanner;
use Hydra\Console\Contracts\CommandInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;

/**
 * The console entrypoint: Hydra commands in, an exit status out.
 *
 * Exists so that `bin/console` does not name Symfony either. Building the
 * application was the last place outside this package that did, and a console
 * script is the one file in an application nobody thinks to grep.
 */
final class ConsoleApplication
{
    private readonly Application $application;

    private readonly CommandScanner $scanner;

    /** @var array<string, callable(): \Symfony\Component\Console\Command\Command> */
    private array $lazy = [];

    public function __construct(string $name = 'Hydra', string $version = 'UNKNOWN', ?CommandScanner $scanner = null)
    {
        $this->application = new Application($name, $version);
        $this->scanner = $scanner ?? new CommandScanner;
    }

    /** Commands cheap enough to build whether or not they are the one being run. */
    public function add(CommandInterface ...$commands): self
    {
        foreach ($commands as $command) {
            $this->application->add(new SymfonyCommand($command, $this->scanner));
        }

        return $this;
    }

    /**
     * Commands built only when named.
     *
     * Keyed by class rather than by command name: the name is on the class's
     * attribute, so writing it out again at the registration site is a second
     * copy that can disagree with the first. What this buys is the thing the
     * attribute exists for — `migrate:fresh` is registered, and listed in help,
     * without its database connection being opened.
     *
     * @param array<class-string<CommandInterface>, callable(): CommandInterface> $factories
     */
    public function addLazy(array $factories): self
    {
        foreach ($factories as $class => $factory) {
            $described = $this->scanner->describe($class);

            // Symfony's LazyCommand answers name and description itself, so
            // `list` and has() read the attribute and never reach the factory.
            // Handing the loader the adapter directly would build every
            // command just to describe it.
            $this->lazy[$described->name] = fn (): LazyCommand => new LazyCommand(
                $described->name,
                [],
                $described->description,
                false,
                fn (): SymfonyCommand => new SymfonyCommand($factory(), $this->scanner),
            );
        }

        // Applied now rather than at run(): the application can then answer
        // what it offers before anything is run, which is what `list` needs
        // and what makes the deferral observable from a test.
        $this->application->setCommandLoader(new FactoryCommandLoader($this->lazy));

        return $this;
    }

    /** @return int the exit status to hand the shell */
    public function run(): int
    {
        return $this->application->run();
    }

    /**
     * The Symfony application underneath, for the things this class does not
     * wrap. Reaching for it couples the caller to Symfony again, so it is worth
     * asking whether the thing wanted belongs on the contract instead.
     */
    public function symfony(): Application
    {
        return $this->application;
    }
}
