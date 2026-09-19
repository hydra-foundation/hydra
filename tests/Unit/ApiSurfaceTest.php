<?php

declare(strict_types=1);

namespace Hydra\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The release gate, held over a pair of fixture trees.
 *
 * bin/release.sh refuses a patch that removes a line from this output, so what
 * counts as a removal is the whole compatibility contract, and until now
 * nothing checked it. The cases below are the changes that have actually been
 * shipped wrong: 0.3.4's six renames, and 0.5.0's required parameter that the
 * name-only version of this tool could not see.
 */
#[CoversNothing]
final class ApiSurfaceTest extends TestCase
{
    private const TOOL = __DIR__ . '/../../bin/api-surface.php';

    private const DIFF = __DIR__ . '/../../bin/api-diff.php';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-surface-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->remove($this->dir);
        }
    }

    public function test_it_lists_a_class_and_its_public_methods(): void
    {
        $surface = $this->surface('<?php
            namespace Acme;
            final class Greeter
            {
                public const GREETING = "hi";
                public function greet(string $name): string { return $name; }
                public static function make(): self { return new self; }
            }
        ');

        $this->assertSame([
            'Acme\Greeter',
            'Acme\Greeter->greet(string $name): string',
            'Acme\Greeter::GREETING',
            'Acme\Greeter::make(): self',
        ], $surface);
    }

    public function test_a_method_named_with_a_reserved_word_is_listed(): void
    {
        // Csp::default() and Client::for() tokenize as T_DEFAULT and T_FOR, and
        // were invisible to the gate: either could have been removed in a patch.
        $surface = $this->surface('<?php
            namespace Acme;
            final class Factory
            {
                public static function for(string $name): self { return new self; }
                public static function default(): self { return new self; }
                public function list(): array { return []; }
                public function make(): callable { return function () {}; }
            }
        ');

        $this->assertSame([
            'Acme\Factory',
            'Acme\Factory->list(): array',
            'Acme\Factory->make(): callable',
            'Acme\Factory::default(): self',
            'Acme\Factory::for(string $name): self',
        ], $surface);
    }

    public function test_a_contract_case_is_its_hooks_and_not_its_tests(): void
    {
        // src/Testing is the one place the relationship is inverted: a user
        // extends the class rather than calling it, so an abstract method is
        // what they must implement and renaming one breaks them, whatever its
        // visibility. Losing a test method cannot break a subclass, and every
        // one of them would otherwise churn this listing on any edit.
        mkdir($this->dir . '/pkg/src/Testing', 0o775, true);
        file_put_contents($this->dir . '/pkg/src/Subject.php', '<?php
            namespace Acme;
            final class Real { public function a(): void {} }
        ');
        file_put_contents($this->dir . '/pkg/src/Testing/ThingContractTestCase.php', '<?php
            namespace Acme\\Testing;
            abstract class ThingContractTestCase
            {
                abstract protected function thing(): Thing;
                public function test_it_does_something(): void {}
                protected function helper(): void {}
            }
        ');

        $this->assertSame([
            'Acme\Real',
            'Acme\Real->a(): void',
            'Acme\Testing\ThingContractTestCase',
            'Acme\Testing\ThingContractTestCase->thing(): Thing',
        ], $this->scan($this->dir));
    }

    public function test_a_concrete_double_under_testing_keeps_its_public_methods(): void
    {
        // The inversion is about being extended, not about the directory. A
        // concrete class under src/Testing is a double a consumer CALLS — a
        // fake logger, a service provider that binds an in-memory store — so
        // its public methods are its whole surface, and renaming one breaks
        // every harness that reads it. Both shapes live side by side in the
        // same directory, which is why the rule cannot be the path alone.
        mkdir($this->dir . '/pkg/src/Testing', 0o775, true);
        file_put_contents($this->dir . '/pkg/src/Testing/FakeThing.php', '<?php
            namespace Acme\\Testing;
            final class FakeThing
            {
                public function records(): array { return []; }
                protected function hidden(): void {}
            }
        ');
        file_put_contents($this->dir . '/pkg/src/Testing/ThingContractTestCase.php', '<?php
            namespace Acme\\Testing;
            abstract class ThingContractTestCase
            {
                abstract protected function thing(): Thing;
                public function test_it_does_something(): void {}
            }
        ');

        $this->assertSame([
            'Acme\Testing\FakeThing',
            'Acme\Testing\FakeThing->records(): array',
            'Acme\Testing\ThingContractTestCase',
            'Acme\Testing\ThingContractTestCase->thing(): Thing',
        ], $this->scan($this->dir));
    }

    public function test_it_leaves_out_what_is_not_surface(): void
    {
        $surface = $this->surface('<?php
            namespace Acme;
            final class Greeter
            {
                private const SECRET = "s";
                private string $hidden = "";
                protected function helper(): void {}
                private function internal(): void {}
                public function greet(): void {}
            }
        ');

        $this->assertSame(['Acme\Greeter', 'Acme\Greeter->greet(): void'], $surface);
    }

    /**
     * Each pair is the same method before and after a change that a consumer
     * would feel. The name is identical on both sides every time, which is
     * precisely why the name-only tool reported nothing.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function breakingChanges(): iterable
    {
        yield 'a new required parameter' => [
            'public function __construct(string $basePath) {}',
            'public function __construct(string $basePath, CspNonce $nonce) {}',
        ];
        yield 'an optional parameter becomes required' => [
            'public function render(string $t, array $data = []): string { return ""; }',
            'public function render(string $t, array $data): string { return ""; }',
        ];
        yield 'a parameter is renamed' => [
            'public function find(int $id): void {}',
            'public function find(int $identifier): void {}',
        ];
        yield 'a parameter is retyped' => [
            'public function find(int $id): void {}',
            'public function find(string $id): void {}',
        ];
        yield 'parameters are reordered' => [
            'public function at(int $x, string $y): void {}',
            'public function at(string $y, int $x): void {}',
        ];
        yield 'the return type narrows' => [
            'public function all(): iterable { return []; }',
            'public function all(): array { return []; }',
        ];
        yield 'a default value changes' => [
            'public function page(int $per = 25): void {}',
            'public function page(int $per = 50): void {}',
        ];
        yield 'an instance method becomes static' => [
            'public function make(): void {}',
            'public static function make(): void {}',
        ];
        yield 'a method is renamed' => [
            'public function handle(): void {}',
            'public function process(): void {}',
        ];
        // The appended-optional tolerance is not a licence to edit the head of
        // the list: a caller's positional arguments land on those same slots.
        yield 'an optional parameter is dropped' => [
            'public function a(int $x, int $y = 0): void {}',
            'public function a(int $x): void {}',
        ];
        yield 'an optional parameter is inserted before an existing one' => [
            'public function a(int $x, int $z = 0): void {}',
            'public function a(int $x, int $y = 0, int $z = 0): void {}',
        ];
        yield 'the return type changes under an appended parameter' => [
            'public function a(int $x): iterable { return []; }',
            'public function a(int $x, int $y = 0): array { return []; }',
        ];
    }

    #[DataProvider('breakingChanges')]
    public function test_a_change_a_consumer_would_feel_reads_as_a_removal(string $before, string $after): void
    {
        $removed = $this->removals(
            '<?php namespace Acme; final class Thing { ' . $before . ' }',
            '<?php namespace Acme; final class Thing { ' . $after . ' }',
        );

        $this->assertNotSame([], $removed, 'the gate would have let this through as a patch');
    }

    /**
     * The other half: a release that only adds must stay a patch, or the gate
     * cries break on every release and stops being read.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function compatibleChanges(): iterable
    {
        yield 'a new method' => [
            'public function a(): void {}',
            'public function a(): void {} public function b(): void {}',
        ];
        yield 'a new optional parameter' => [
            'public function a(int $x): void {}',
            'public function a(int $x, int $y = 0): void {}',
        ];
        yield 'a new constant' => [
            'public function a(): void {}',
            'public const B = 1; public function a(): void {}',
        ];
        yield 'a private method appears' => [
            'public function a(): void {}',
            'public function a(): void {} private function b(): void {}',
        ];
        // Whitespace and a trailing comma are a diff in the file and nothing
        // to a caller; a gate that flagged them would be passed --minor by
        // reflex, which is the same as not having one.
        yield 'reformatting only' => [
            'public function a(int $x, string $y): void {}',
            "public function a(\n    int \$x,\n    string \$y,\n): void {}",
        ];
        yield 'a comment inside the signature' => [
            'public function a(int $x): void {}',
            'public function a(/* the id */ int $x): void {}',
        ];
        yield 'two optional parameters appended at once' => [
            'public function a(int $x): void {}',
            'public function a(int $x, int $y = 0, ?string $z = null): void {}',
        ];
        yield 'a variadic tail appears' => [
            'public function a(int $x): void {}',
            'public function a(int $x, int ...$rest): void {}',
        ];
        // The commas inside the default are not parameter separators, and the
        // parentheses inside it are not the end of the signature.
        yield 'an appended default carries its own punctuation' => [
            'public function a(int $x): void {}',
            'public function a(int $x, array $o = [1, 2], ?Thing $t = null): void {}',
        ];
    }

    #[DataProvider('compatibleChanges')]
    public function test_an_addition_alone_is_not_a_removal(string $before, string $after): void
    {
        $removed = $this->removals(
            '<?php namespace Acme; final class Thing { ' . $before . ' }',
            '<?php namespace Acme; final class Thing { ' . $after . ' }',
        );

        $this->assertSame([], $removed);
    }

    public function test_a_renamed_class_reads_as_a_removal(): void
    {
        // 0.3.4 was six of these, shipped as a patch and pulled off Packagist
        // an hour later.
        $removed = $this->removals(
            '<?php namespace Acme; final class Old { public function a(): void {} }',
            '<?php namespace Acme; final class New_ { public function a(): void {} }',
        );

        $this->assertSame(['Acme\Old', 'Acme\Old->a(): void'], $removed);
    }

    public function test_an_enum_case_and_its_value_are_surface(): void
    {
        $surface = $this->surface('<?php
            namespace Acme;
            enum Colour: string
            {
                case Red = "red";
                case Blue = "blue";
            }
        ');

        $this->assertSame([
            'Acme\Colour',
            'Acme\Colour::Blue = "blue"',
            'Acme\Colour::Red = "red"',
        ], $surface);
    }

    public function test_a_changed_backing_value_reads_as_a_removal(): void
    {
        // Every from() and tryFrom() a consumer wrote against the old string
        // starts throwing, which is a break the case name alone cannot show.
        $removed = $this->removals(
            '<?php namespace Acme; enum Colour: string { case Red = "red"; }',
            '<?php namespace Acme; enum Colour: string { case Red = "crimson"; }',
        );

        $this->assertSame(['Acme\Colour::Red = "red"'], $removed);
    }

    public function test_a_switch_inside_a_method_is_not_read_as_an_enum_case(): void
    {
        $surface = $this->surface('<?php
            namespace Acme;
            enum Colour: string
            {
                case Red = "red";
                public function describe(string $in): string
                {
                    switch ($in) {
                        case "loud": return "loud";
                        default: return "quiet";
                    }
                }
            }
        ');

        $this->assertSame([
            'Acme\Colour',
            'Acme\Colour->describe(string $in): string',
            'Acme\Colour::Red = "red"',
        ], $surface);
    }

    public function test_a_closure_in_a_method_body_is_not_read_as_a_method(): void
    {
        $surface = $this->surface('<?php
            namespace Acme;
            final class Thing
            {
                public function run(): callable
                {
                    $inner = function (int $x): int { return $x; };
                    return $inner;
                }
            }
        ');

        $this->assertSame(['Acme\Thing', 'Acme\Thing->run(): callable'], $surface);
    }

    public function test_an_anonymous_class_contributes_no_name(): void
    {
        $surface = $this->surface('<?php
            namespace Acme;
            final class Thing
            {
                public function make(): object
                {
                    return new class { public function hidden(): void {} };
                }
            }
        ');

        $this->assertContains('Acme\Thing->make(): object', $surface);
        $this->assertNotContains('Acme\\\\hidden', $surface);
    }

    public function test_an_interface_method_is_surface_without_a_visibility_keyword(): void
    {
        $surface = $this->surface('<?php
            namespace Acme;
            interface Reader
            {
                public function read(string $key): mixed;
                public function has(string $key): bool;
            }
        ');

        $this->assertSame([
            'Acme\Reader',
            'Acme\Reader->has(string $key): bool',
            'Acme\Reader->read(string $key): mixed',
        ], $surface);
    }

    public function test_it_refuses_a_directory_it_was_not_given(): void
    {
        exec(sprintf('php %s 2>&1', escapeshellarg(self::TOOL)), $output, $status);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('usage:', implode("\n", $output));
    }

    /**
     * Only files under a src/ directory count, the way the packages are laid
     * out. A tests/ fixture must never reach the comparison.
     */
    public function test_it_reads_only_the_src_directories(): void
    {
        mkdir($this->dir . '/one/tests', 0o775, true);
        file_put_contents(
            $this->dir . '/one/tests/Fixture.php',
            '<?php namespace Acme\Tests; final class Fixture { public function a(): void {} }',
        );

        $this->write('one', '<?php namespace Acme; final class Real { public function a(): void {} }');

        $this->assertSame(['Acme\Real', 'Acme\Real->a(): void'], $this->scan($this->dir));
    }

    /**
     * The lines in $before that $after no longer has, which is what
     * bin/release.sh refuses on a patch tag: the two trees scanned, then
     * compared by the same tool the gate runs, rather than by an array_diff
     * that would pass cases the gate itself fails.
     *
     * @return list<string>
     */
    private function removals(string $before, string $after): array
    {
        $old = $this->dir . '/old.surface';
        $new = $this->dir . '/new.surface';

        file_put_contents($old, implode("\n", $this->scan($this->tree('old', $before))) . "\n");
        file_put_contents($new, implode("\n", $this->scan($this->tree('new', $after))) . "\n");

        exec(
            sprintf(
                'php %s %s %s',
                escapeshellarg(self::DIFF),
                escapeshellarg($old),
                escapeshellarg($new),
            ),
            $output,
            $status,
        );

        $this->assertSame(0, $status, 'api-diff.php failed');

        return array_values(array_filter($output, static fn (string $l): bool => $l !== ''));
    }

    /** @return list<string> */
    private function surface(string $source): array
    {
        return $this->scan($this->tree('only', $source));
    }

    private function tree(string $name, string $source): string
    {
        $root = $this->dir . '/' . $name;
        mkdir($root . '/pkg/src', 0o775, true);
        file_put_contents($root . '/pkg/src/Subject.php', $source);

        return $root;
    }

    private function write(string $package, string $source): void
    {
        mkdir($this->dir . '/' . $package . '/src', 0o775, true);
        file_put_contents($this->dir . '/' . $package . '/src/Subject.php', $source);
    }

    /** @return list<string> */
    private function scan(string $root): array
    {
        exec(
            sprintf('php %s %s', escapeshellarg(self::TOOL), escapeshellarg($root)),
            $output,
            $status,
        );

        $this->assertSame(0, $status, 'api-surface.php failed on ' . $root);

        return array_values(array_filter($output, static fn (string $l): bool => $l !== ''));
    }

    private function remove(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->remove($path) : unlink($path);
        }

        rmdir($dir);
    }
}
