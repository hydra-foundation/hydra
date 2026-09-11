<?php

declare(strict_types=1);

namespace Hydra\Admin\Console;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\ModuleScanner;
use Hydra\Admin\Screens\PageScreen;
use Hydra\View\Contracts\ViewInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Admin routes command
 *
 * Prints what the modules compiled to: the receipt for everything the admin
 * generated on your behalf.
 *
 * A page screen names a template the admin cannot check when the module is
 * declared — compile() has no view to ask. Nothing else looks, so a name with a
 * typo in it routes, gates and breadcrumbs correctly and then 500s the moment a
 * visitor follows the link. That is what this command is for: given a view, it
 * says which templates are missing and fails rather than only reporting.
 */
#[AsCommand(
    name: 'admin:routes',
    description: 'Show the routes and abilities the admin modules compile to',
)]
final class AdminRoutesCommand extends Command
{
    private const NONE = '—';

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ?ViewInterface $view = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $blueprints = $this->registry->all();
        $routes = (new ModuleScanner)->scan($blueprints, $this->registry->prefix());
        $missing = [];

        $io->table(
            ['Method', 'Path', 'Name', 'Ability', 'Template'],
            array_map(
                function (array $route) use ($blueprints, &$missing): array {
                    $template = $this->template($blueprints, $route['name']);

                    if ($template !== null && $this->view !== null && !$this->view->has($template)) {
                        $missing[] = $template;
                        $template = "<error>{$template}</error>";
                    }

                    return [
                        $route['method'],
                        $route['path'],
                        $route['name'],
                        $this->ability($blueprints, $route['name']) ?? self::NONE,
                        $template ?? self::NONE,
                    ];
                },
                $routes,
            ),
        );

        if ($missing === []) {
            return Command::SUCCESS;
        }

        $io->error(sprintf(
            'No template found for: %s. The screen will route and then fail to render.',
            implode(', ', array_unique($missing)),
        ));

        return Command::FAILURE;
    }

    /**
     * The template a route renders, or null when the admin renders it from a
     * blueprint — a table and a form are the package's own templates, and are
     * covered by the package's own tests.
     *
     * @param array<string, Blueprint> $blueprints
     */
    private function template(array $blueprints, string $name): ?string
    {
        $screen = $this->screen($blueprints, $name);

        return $screen instanceof PageScreen ? $screen->template() : null;
    }

    /**
     * @param array<string, Blueprint> $blueprints
     */
    private function ability(array $blueprints, string $name): ?string
    {
        [$slug] = explode('.', $name, 2);

        return $this->screen($blueprints, $name)?->ability() ?? $blueprints[$slug]->ability;
    }

    /**
     * The route name is "slug.screen", plus ".submit" on the POST a submittable
     * screen adds — which answers at the same screen, so it answers to the same
     * ability and renders the same template.
     *
     * @param array<string, Blueprint> $blueprints
     */
    private function screen(array $blueprints, string $name): ?ScreenInterface
    {
        [$slug, $screen] = explode('.', $name, 2);

        if (str_ends_with($screen, '.submit')) {
            $screen = substr($screen, 0, -strlen('.submit'));
        }

        return $blueprints[$slug]->screen($screen);
    }
}
