<?php

declare(strict_types=1);

namespace Hydra\SymfonyConsole;

use Hydra\Console\Argument;
use Hydra\Console\CommandScanner;
use Hydra\Console\Contracts\CommandInterface;
use Hydra\Console\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One Hydra command, presented to Symfony as one of its own.
 *
 * This is the whole of the coupling. Everything the framework and its
 * applications write implements {@see CommandInterface}; this class is the only
 * thing in the tree that extends Symfony's {@see Command}, which is what makes
 * a breaking change in Symfony a change to one file rather than to every
 * command in every application.
 */
final class SymfonyCommand extends Command
{
    public function __construct(
        private readonly CommandInterface $command,
        ?CommandScanner $scanner = null,
    ) {
        $described = ($scanner ?? new CommandScanner)->describe($command);

        parent::__construct($described->name);

        $this->setDescription($described->description);
    }

    protected function configure(): void
    {
        foreach ($this->command->arguments() as $argument) {
            $this->addArgument(
                $argument->name,
                $this->argumentMode($argument),
                $argument->description,
                $argument->default,
            );
        }

        foreach ($this->command->options() as $option) {
            $this->addOption(
                $option->name,
                $option->shortcut,
                $option->takesValue ? InputOption::VALUE_REQUIRED : InputOption::VALUE_NONE,
                $option->description,
                // A flag's default is Symfony's own false; passing null here
                // would make getOption() answer null rather than false and
                // every flag() read would come back false-but-not-because-of-absence.
                $option->takesValue ? $option->default : null,
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->command->execute(
            new SymfonyInput($input),
            new SymfonyOutput(new SymfonyStyle($input, $output)),
        )->value;
    }

    private function argumentMode(Argument $argument): int
    {
        return $argument->required ? InputArgument::REQUIRED : InputArgument::OPTIONAL;
    }
}
