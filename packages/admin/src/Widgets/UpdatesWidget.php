<?php

declare(strict_types=1);

namespace Hydra\Admin\Widgets;

use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Admin\Updates\Standing;
use Hydra\Admin\Updates\UpdateCheck;

/** Whether a newer Hydra has been released, and what reaching it takes. */
final class UpdatesWidget implements PresenterInterface
{
    public function __construct(private readonly UpdateCheck $check) {}

    public function present(): array
    {
        $update = $this->check->update();
        $installed = ['label' => 'Installed', 'value' => $update->installed];

        return match ($update->standing) {
            Standing::Off => $this->card(Status::Ok, $update->installed, 'not checking', [], 'HYDRA_UPDATE_CHECK=false'),
            Standing::Unreleased => $this->card(Status::Ok, $update->installed, 'not a tagged release'),
            Standing::Unknown => $this->card(Status::Warning, 'Unknown', 'could not read the release feed', [$installed], 'Tried again within the hour.'),
            Standing::Current => $this->card(Status::Ok, $update->installed, 'up to date'),
            Standing::Patch => $this->card(
                $update->security ? Status::Down : Status::Warning,
                (string) $update->newest,
                $update->security ? 'security fix available' : 'patch available',
                [$installed],
                'Run hydra:upgrade on a development checkout, then deploy the lock.',
                $update->notes === null ? null : ['href' => $update->notes, 'text' => 'Release notes'],
            ),
            Standing::Series => $this->card(
                Status::Warning,
                (string) $update->newest,
                'new series',
                [$installed],
                sprintf('^%s does not reach %s on its own.', self::series($update->installed), self::series((string) $update->newest)),
                $update->notes === null ? null : ['href' => $update->notes, 'text' => 'Upgrade notes'],
            ),
        };
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     * @param array{href: string, text: string}|null $link
     * @return array<string, mixed>
     */
    private function card(Status $status, string $headline, string $caption, array $rows = [], ?string $note = null, ?array $link = null): array
    {
        return [
            'status' => $status,
            'headline' => $headline,
            'caption' => $caption,
            'rows' => $rows,
            'note' => $note,
            'link' => $link,
        ];
    }

    private static function series(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }
}
