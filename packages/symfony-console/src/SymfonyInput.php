<?php

declare(strict_types=1);

namespace Hydra\SymfonyConsole;

use Hydra\Console\Contracts\InputInterface;
use Symfony\Component\Console\Input\InputInterface as SymfonyInputInterface;

/**
 * Hydra's input, answered by Symfony's.
 */
final readonly class SymfonyInput implements InputInterface
{
    public function __construct(private SymfonyInputInterface $input) {}

    public function hasArgument(string $name): bool
    {
        return $this->input->hasArgument($name) && $this->input->getArgument($name) !== null;
    }

    public function argument(string $name, string $default = ''): string
    {
        if (!$this->input->hasArgument($name)) {
            return $default;
        }

        $value = $this->input->getArgument($name);

        // An argument declared but not typed is null, and an array argument is
        // a shape this contract does not offer — both fall back rather than
        // becoming "Array" or a deprecation notice.
        return is_scalar($value) ? (string) $value : $default;
    }

    public function option(string $name, string $default = ''): string
    {
        if (!$this->input->hasOption($name)) {
            return $default;
        }

        $value = $this->input->getOption($name);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function flag(string $name): bool
    {
        return $this->input->hasOption($name) && $this->input->getOption($name) === true;
    }
}
