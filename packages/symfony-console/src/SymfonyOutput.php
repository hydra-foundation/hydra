<?php

declare(strict_types=1);

namespace Hydra\SymfonyConsole;

use Hydra\Console\Contracts\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Hydra's output, drawn by Symfony's style.
 *
 * Every method here is one call deep, which is the point: the seam exists so
 * that the framework's commands do not extend a third-party class, not so that
 * the drawing can be replaced. The drawing is the part worth keeping.
 */
final readonly class SymfonyOutput implements OutputInterface
{
    public function __construct(private SymfonyStyle $style) {}

    public function write(string $line): void
    {
        $this->style->writeln($line);
    }

    public function success(string $message): void
    {
        $this->style->success($message);
    }

    public function error(string $message): void
    {
        $this->style->error($message);
    }

    public function warning(string $message): void
    {
        $this->style->warning($message);
    }

    public function note(string $message): void
    {
        $this->style->note($message);
    }

    public function table(array $headers, array $rows): void
    {
        $this->style->table($headers, $rows);
    }

    public function listing(array $items): void
    {
        $this->style->listing($items);
    }

    public function ask(string $question, ?string $default = null, ?callable $validator = null): string
    {
        return (string) $this->style->ask($question, $default, $validator);
    }

    public function askHidden(string $question, ?callable $validator = null): string
    {
        return (string) $this->style->askHidden($question, $validator);
    }

    public function confirm(string $question, bool $default = true): bool
    {
        return $this->style->confirm($question, $default);
    }
}
