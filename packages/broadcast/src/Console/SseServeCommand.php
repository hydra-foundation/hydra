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

        // SIGQUIT too: an image built on php:fpm inherits STOPSIGNAL SIGQUIT,
        // and `docker stop` would otherwise end the hub with its status key
        // still saying it runs. Without pcntl the kill ends it all the same,
        // with the key left to expire rather than cleared.
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
                pcntl_signal($signal, static fn () => $server->halt());
            }
        }

        $this->logger->info("The SSE hub is listening on {$where}.");
        $output->note("Listening on {$where}. Stop with Ctrl-C.");

        $server->run();

        $this->logger->info('The SSE hub stopped.');

        return ExitCode::Success;
    }
}
