<?php

declare(strict_types=1);

namespace Hydra\CommonMark;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\View\Contracts\MarkdownInterface;

/**
 * Fills view's MarkdownInterface with CommonMark. The options are the app's,
 * given here because the renderer's constructor argument is optional and an
 * autowiring container would skip it.
 */
final class CommonMarkServiceProvider extends ServiceProvider
{
    public function __construct(private readonly MarkdownOptions $options = new MarkdownOptions) {}

    public function register(ContainerInterface $container): void
    {
        $container->singleton(MarkdownOptions::class, fn (): MarkdownOptions => $this->options);
        $container->singleton(MarkdownInterface::class, fn (): MarkdownInterface => new CommonMarkRenderer($this->options));
    }
}
