<?php

declare(strict_types=1);

namespace Hydra\Admin\Console;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\ModuleScanner;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Screens\PageScreen;
use Hydra\Admin\Screens\WidgetScreen;
use Hydra\View\Contracts\ViewInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints what the modules compiled to: the receipt for everything the admin
 * generated on your behalf.
 *
 * A page screen names a template the admin cannot check when the module is
 * declared, since compile() has no view to ask. Nothing else looks, so a name
 * with a typo routes, gates and breadcrumbs correctly and then 500s the moment a
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
                    [$slug] = explode('.', $route['name'], 2);
                    $screen = $this->screen($blueprints, $route['name']);
                    $template = $this->template($screen);

                    if ($template === null) {
                        $cell = $screen instanceof WidgetScreen
                            ? $this->cardCount($blueprints[$slug], $screen)
                            : self::NONE;
                    } elseif ($this->view !== null && !$this->view->has($template)) {
                        $missing[] = $template;
                        $cell = "<error>{$template}</error>";
                    } else {
                        $cell = $template;
                    }

                    return [
                        $route['method'],
                        $route['path'],
                        $route['name'],
                        $this->ability($blueprints, $route['name']) ?? self::NONE,
                        $cell,
                    ];
                },
                $routes,
            ),
        );

        // A dashboard's cards render templates of their own, and no route names
        // one: the widget route serves every card on the screen. They go wrong
        // exactly the way a page screen's template does, so they are checked
        // here too, one pass over the declarations rather than over the routes.
        $missing = [...$missing, ...$this->missingWidgetTemplates($blueprints)];

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
     * blueprint: a table and a form are the package's own templates, and are
     * covered by the package's own tests.
     *
     * Only a name the view can be asked for belongs here. A widget route names
     * no single template — it serves every card on its dashboard — so it is
     * printed by {@see cardCount()} and checked by
     * {@see missingWidgetTemplates()} instead.
     */
    private function template(?ScreenInterface $screen): ?string
    {
        return match (true) {
            $screen instanceof PageScreen, $screen instanceof DashboardScreen => $screen->template(),
            default => null,
        };
    }

    /**
     * What the widget route serves, as a count rather than a list: a dashboard
     * of a dozen cards would otherwise put a dozen template names in one cell.
     * The names themselves are only interesting when one is missing, and the
     * error below names those.
     */
    private function cardCount(Blueprint $blueprint, WidgetScreen $screen): string
    {
        $dashboard = $blueprint->screen($screen->dashboard());
        // The summary strip answers at this route like any other card, so a
        // count that left it out would not add up to what the route serves.
        $cards = $dashboard instanceof DashboardScreen
            ? count($dashboard->cards()) + ($dashboard->summary() === null ? 0 : 1)
            : 0;

        return $cards === 1 ? '1 widget' : $cards . ' widgets';
    }

    /**
     * @param array<string, Blueprint> $blueprints
     * @return list<string>
     */
    private function missingWidgetTemplates(array $blueprints): array
    {
        if ($this->view === null) {
            return [];
        }

        $missing = [];

        foreach ($blueprints as $blueprint) {
            foreach ($blueprint->screens as $screen) {
                if (!$screen instanceof DashboardScreen) {
                    continue;
                }

                foreach ([...$screen->cards(), ...array_filter([$screen->summary()])] as $card) {
                    if (!$this->view->has($card->template())) {
                        $missing[] = $card->template();
                    }
                }
            }
        }

        return $missing;
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
     * screen adds, which answers at the same screen, so it answers to the same
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
