<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\ArrayInput;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Console\Commands\MakeClassCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The generator base, driven through a fixture subclass because the concrete
 * ones (make:controller, make:ability) ship in the skeleton rather than here.
 * That is the whole reason this needs its own: the base is a published
 * extension point with no implementation in this tree to exercise it.
 */
#[CoversClass(MakeClassCommand::class)]
final class MakeClassCommandTest extends TestCase
{
    private string $base;
    private string $dir;

    private FakeOutput $output;

    protected function setUp(): void
    {
        $this->base = $this->dir = sys_get_temp_dir() . '/hydra-make-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        // Recursive: one test points the command at a nested path to prove it
        // creates the tree, and leaving those behind litters the temp dir.
        if (is_dir($this->base)) {
            $this->remove($this->base);
        }
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

    /**
     * A loose name is whatever somebody typed at a shell, and the generator
     * has one job before it writes: turn it into a class name that compiles.
     */
    #[DataProvider('looseNames')]
    public function test_it_normalises_the_name_it_was_given(string $given, string $expected): void
    {
        $this->make($given);

        $this->assertFileExists($this->dir . '/' . $expected . '.php');
    }

    /** @return iterable<string, array{string, string}> */
    public static function looseNames(): iterable
    {
        yield 'already pascal case' => ['BlogPost', 'BlogPostController'];
        yield 'spaced words' => ['blog post', 'BlogPostController'];
        yield 'hyphenated' => ['blog-post', 'BlogPostController'];
        yield 'snake case' => ['blog_post', 'BlogPostController'];
        yield 'lower single word' => ['post', 'PostController'];
        yield 'surrounding punctuation' => ['  post!  ', 'PostController'];
        yield 'digits survive' => ['oauth2', 'Oauth2Controller'];
        // Added once, not once per invocation: "PostController" must not
        // become "PostControllerController".
        yield 'suffix already present' => ['PostController', 'PostController'];
    }

    public function test_a_name_with_nothing_to_capitalise_is_refused(): void
    {
        $code = $this->make('---');

        // Invalid rather than Failure: the command could not run at all, as
        // against running and answering no. The shell can tell them apart.
        $this->assertSame(ExitCode::Invalid, $code);
        $this->output->assertError('at least one letter or digit');
    }

    public function test_it_writes_the_stub_the_subclass_supplied(): void
    {
        $this->make('post');

        $this->assertStringContainsString(
            'final class PostController',
            (string) file_get_contents($this->dir . '/PostController.php'),
        );
    }

    public function test_it_creates_the_target_directory(): void
    {
        // Nested, because the skeleton's controller directory may not exist in
        // a checkout where nobody has generated one yet.
        $this->dir .= '/src/Http/Controllers';

        $code = $this->make('post');

        $this->assertSame(ExitCode::Success, $code);
        $this->assertFileExists($this->dir . '/PostController.php');
    }

    public function test_it_refuses_to_overwrite_without_force(): void
    {
        $this->make('post');
        file_put_contents($this->dir . '/PostController.php', 'hand written');

        $code = $this->make('post');

        $this->assertSame(ExitCode::Failure, $code);
        $this->output->assertError('already exists');
        $this->assertSame('hand written', file_get_contents($this->dir . '/PostController.php'));
    }

    public function test_force_overwrites(): void
    {
        $this->make('post');
        file_put_contents($this->dir . '/PostController.php', 'hand written');

        $code = $this->make('post', ['force']);

        $this->assertSame(ExitCode::Success, $code);
        $this->assertStringContainsString(
            'final class PostController',
            (string) file_get_contents($this->dir . '/PostController.php'),
        );
    }

    public function test_the_after_create_hook_runs_with_the_resolved_class_name(): void
    {
        // The skeleton uses this to remind the user to register what it wrote,
        // so it has to see the normalised name rather than the typed one.
        $command = new StubMakeCommand($this->dir);

        $command->execute(ArrayInput::withArguments(['name' => 'blog post']), new FakeOutput);

        $this->assertSame(['BlogPostController'], $command->created);
    }

    public function test_the_hook_does_not_run_when_nothing_was_written(): void
    {
        $command = new StubMakeCommand($this->dir);

        $command->execute(ArrayInput::withArguments(['name' => '---']), new FakeOutput);

        $this->assertSame([], $command->created);
    }

    /**
     * Run the generator and keep what it said, so a caller can assert on the
     * exit code and the message without rebuilding either.
     *
     * @param list<string> $flags
     */
    private function make(string $name, array $flags = []): ExitCode
    {
        $this->output = new FakeOutput;

        return (new StubMakeCommand($this->dir))->execute(
            new ArrayInput(arguments: ['name' => $name], flags: $flags),
            $this->output,
        );
    }
}

/** What a skeleton's make:controller is, minus the stub's real body. */
final class StubMakeCommand extends MakeClassCommand
{
    /** @var list<string> */
    public array $created = [];

    protected function nameHint(): string
    {
        return 'The controller name, e.g. "post"';
    }

    protected function suffix(): string
    {
        return 'Controller';
    }

    protected function stub(string $class): string
    {
        return "<?php\n\nfinal class {$class} {}\n";
    }

    protected function afterCreate(OutputInterface $output, string $class): void
    {
        $this->created[] = $class;
    }
}
