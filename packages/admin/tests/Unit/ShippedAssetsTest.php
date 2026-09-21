<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The half of the theming contract that belongs to the package. The sheet is
 * shipped here and the palette it draws on is the application's, so the sheet
 * may not name a colour: one hex literal is a rule no theme can reach and a
 * patch of the admin that stays light when everything around it goes dark.
 */
#[CoversClass(AdminServiceProvider::class)]
final class ShippedAssetsTest extends TestCase
{
    public function test_the_stylesheet_names_no_colour_of_its_own(): void
    {
        preg_match_all(
            '~#[0-9a-fA-F]{3,8}\b|\brgba?\([^)]*\)~',
            $this->read('admin.css'),
            $matches,
        );

        $this->assertSame([], $matches[0], 'admin.css hardcodes a colour no theme can reach.');
    }

    public function test_the_stylesheet_asks_only_for_tokens_it_documents(): void
    {
        // The tokens are the interface between this package and whatever
        // palette an application has. Asking for one nobody was told about
        // renders as an empty value the browser decides for itself, which is
        // the failure that looks like a design choice.
        preg_match_all('~var\(\s*(--[a-z0-9-]+)~i', $this->read('admin.css'), $matches);

        $asked = array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $token): bool => !str_starts_with($token, '--bs-'),
        )));

        sort($asked);

        $this->assertSame($this->documented(), $asked, sprintf(
            'admin.css asks for tokens README.md does not list: %s',
            implode(', ', array_diff($asked, $this->documented())),
        ));
    }

    /**
     * The tokens the package's own README publishes as the contract, read back
     * out of it so the list cannot drift from the prose that states it.
     *
     * @return list<string>
     */
    private function documented(): array
    {
        preg_match_all('~^- `(--[a-z0-9-]+)`~im', $this->read('../README.md'), $matches);

        $tokens = array_values(array_unique($matches[1]));
        sort($tokens);

        return $tokens;
    }

    private function read(string $name): string
    {
        $file = AdminServiceProvider::assets() . '/' . $name;

        return file_get_contents($file) ?: self::fail("Missing asset: {$name}.");
    }
}
