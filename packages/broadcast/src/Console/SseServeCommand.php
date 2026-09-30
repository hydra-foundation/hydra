<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Console;

use Closure;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\Server;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Psr\Log\LoggerInterface;

/**
 * The hub, in the foreground: its own compose service, never php-fpm, whose
 * workers a stream would hold for as long as the tab is open.
 */
#[AsCommand(
    name: 'sse:serve',
    description: 'Hold browser event streams and fan broadcasts out to them',
)]
final class SseServeCommand extends Command
{
    /** @param Closure(resource): Server $hub builds the server on the bound listener */
    public function __construct(
        private readonly HubConfig $config,
        private readonly Closure $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $where = "{$this->config->host}:{$this->config->port}";
        $listener = @stream_socket_server("tcp://{$where}", $errno, $error);

        if ($listener === false) {
            $output->error("Could not listen on {$where}: {$error}");

            return ExitCode::Failure;
        }

        $server = ($this->hub)($listener);

        // Without pcntl, docker's kill ends the process all the same, only
        // with the status key left to expire rather than cleared.
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static fn () => $server->halt());
            pcntl_signal(SIGINT, static fn () => $server->halt());
        }

        $this->logger->info("The SSE hub is listening on {$where}.");
        $output->note("Listening on {$where}. Stop with Ctrl-C.");

        $server->run();

        $this->logger->info('The SSE hub stopped.');

        return ExitCode::Success;
    }
}
