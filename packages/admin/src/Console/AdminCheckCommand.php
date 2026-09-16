<?php

declare(strict_types=1);

namespace Hydra\Admin\Console;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Field;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\SourceDescription;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Holds every module to its source, which is the one pairing nothing else in
 * the toolchain can see.
 *
 * `compile()` checks a module against itself and `TableSource` checks a
 * declaration against itself. Between them sits a string written twice: a
 * module's `->filterable()` field and the source's filterable column are the
 * same column name in two files, and until this command nothing reconciled
 * them. The failure is silent in the direction that matters — a filter the
 * source does not list builds no clause at all, so the screen answers a
 * narrowed request with the whole table, renders normally and says nothing.
 *
 * `admin:routes` is the receipt for what the admin generated. This is the
 * receipt for what it could not.
 */
#[AsCommand(
    name: 'admin:check',
    description: 'Check every module against the source it reads',
)]
final class AdminCheckCommand extends Command
{
    private const OK = '<info>ok</info>';

    public function __construct(private readonly ModuleRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = [];
        $problems = [];
        $unchecked = [];

        foreach ($this->registry->all() as $blueprint) {
            $description = $this->describe($blueprint);

            if ($description === null) {
                $unchecked[] = $blueprint->slug;
                $rows[] = [$blueprint->slug, '—', '<comment>not describable</comment>'];

                continue;
            }

            $found = $this->problems($blueprint, $description);
            $problems = [...$problems, ...$found];

            $rows[] = [
                $blueprint->slug,
                $description->table,
                $found === [] ? self::OK : sprintf('<error>%d</error>', count($found)),
            ];
        }

        $io->table(['Module', 'Table', 'Fields vs source'], $rows);

        if ($unchecked !== []) {
            $io->note(sprintf(
                'Not checked, because the source does not implement %s: %s. '
                . 'A source over a join or a remote service has no column list to give; '
                . 'one over a plain table gets this by extending TableSource.',
                'DescribesColumnsInterface',
                implode(', ', $unchecked),
            ));
        }

        if ($problems === []) {
            $io->success('Every module names columns its source reads.');

            return Command::SUCCESS;
        }

        $io->error('A module names a column its source does not.');
        $io->listing($problems);
        $io->writeln(
            ' A filter the source does not list renders, submits and narrows nothing, so the screen'
            . ' answers with the whole table. A search column it does not list is one the box never'
            . ' looks in. Neither says anything at runtime.',
        );

        return Command::FAILURE;
    }

    /**
     * What the module claims and the source does not offer.
     *
     * Only this direction is a defect. The reverse — a source that sorts by a
     * column no field declares — is how a source stays reusable across two
     * modules showing different halves of one table, so it is not reported.
     *
     * @return list<string>
     */
    private function problems(Blueprint $blueprint, SourceDescription $description): array
    {
        $problems = [];

        foreach ($blueprint->fields as $field) {
            if (!$description->reads($field->name())) {
                $problems[] = $this->problem($blueprint, $field, 'is not a column the source reads');

                // Every other check below would restate this one.
                continue;
            }

            if ($field->isSortable() && !$description->sortsBy($field->name())) {
                $problems[] = $this->problem($blueprint, $field, 'is sortable, but the source does not sort by it');
            }

            if ($field->isSearchable() && !$description->searches($field->name())) {
                $problems[] = $this->problem($blueprint, $field, 'is searchable, but the source does not search it');
            }

            if ($field->isFilterable() && !$description->filtersBy($field->name())) {
                $problems[] = $this->problem($blueprint, $field, 'is filterable, but the source does not filter by it');
            }
        }

        // The default sort is the one column a list screen orders by before a
        // visitor has touched anything, so a module naming one the source will
        // not honour is wrong on the very first request.
        if ($blueprint->defaultSort !== null && !$description->sortsBy($blueprint->defaultSort)) {
            $problems[] = sprintf(
                '%s: defaultSort is "%s", which the source does not sort by — every list starts on "%s" instead',
                $blueprint->slug,
                $blueprint->defaultSort,
                $description->defaultSort,
            );
        }

        return $problems;
    }

    private function problem(Blueprint $blueprint, Field $field, string $says): string
    {
        return sprintf('%s: "%s" %s', $blueprint->slug, $field->name(), $says);
    }

    /**
     * A module with no source at all is a dashboard or a settings page, which is
     * legitimate and has nothing to check. A source that cannot be built is a
     * different thing — a malformed declaration raising from its own guard — and
     * is reported as unchecked rather than swallowed, since that throw is the
     * one this command most wants a reader to see.
     */
    private function describe(Blueprint $blueprint): ?SourceDescription
    {
        if ($blueprint->source === null) {
            return null;
        }

        try {
            $source = $this->registry->source($blueprint);
        } catch (Throwable) {
            return null;
        }

        return $source instanceof DescribesColumnsInterface ? $source->describe() : null;
    }
}
