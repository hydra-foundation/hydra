<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Parsing a .env file and reading typed values out of it. Weighted towards the
 * quoting and inline-comment cases, where a wrong answer is a silently
 * misconfigured application rather than an error.
 */
#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    private string $dir;

    /** @var list<string> keys this test's .env may have exported to the process env */
    private array $written = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-env-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Environment exports .env values to the real process environment,
        // and the real environment beats the file, so scrub every key this
        // test wrote or a stale export leaks into the next test.
        foreach ($this->written as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->written = [];

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    private function writeEnv(string $contents): Environment
    {
        file_put_contents($this->dir . '/.env', $contents);

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            $this->written[] = trim(explode('=', $line, 2)[0]);
        }

        return new Environment($this->dir);
    }

    public function test_reads_plain_value(): void
    {
        $env = $this->writeEnv("APP_NAME=hydra\n");
        $this->assertSame('hydra', $env->get('APP_NAME'));
    }

    public function test_falsy_zero_is_not_treated_as_missing(): void
    {
        // Regression: the old `?:` chain returned the default for "0".
        $env = $this->writeEnv("DEBUG=0\n");
        $this->assertSame('0', $env->get('DEBUG', 'DEFAULT'));
    }

    public function test_strips_surrounding_quotes(): void
    {
        $env = $this->writeEnv("NAME=\"hydra framework\"\nALT='single'\n");
        $this->assertSame('hydra framework', $env->get('NAME'));
        $this->assertSame('single', $env->get('ALT'));
    }

    public function test_strips_inline_comment_from_unquoted_value(): void
    {
        // Regression: the shipped .env.example uses inline comments; keeping
        // them in the value silently corrupted APP_DEBUG, APP_KEY and DB_HOST.
        $env = $this->writeEnv("APP_DEBUG=true  # Set to false in production\n");
        $this->assertSame('true', $env->get('APP_DEBUG'));
        $this->assertTrue($env->bool('APP_DEBUG'));
    }

    public function test_strips_inline_comment_with_single_space(): void
    {
        $env = $this->writeEnv("DB_HOST=localhost # or 127.0.0.1\n");
        $this->assertSame('localhost', $env->get('DB_HOST'));
    }

    public function test_hash_inside_quoted_value_is_preserved(): void
    {
        // Inside quotes, # is data, not the start of a comment.
        $env = $this->writeEnv("SECRET=\"abc#123 # not a comment\"\nALT='x # y'\n");
        $this->assertSame('abc#123 # not a comment', $env->get('SECRET'));
        $this->assertSame('x # y', $env->get('ALT'));
    }

    public function test_url_with_fragment_inside_quotes_survives(): void
    {
        $env = $this->writeEnv("DOCS_URL=\"https://example.com/page#section\"\n");
        $this->assertSame('https://example.com/page#section', $env->get('DOCS_URL'));
    }

    public function test_mismatched_quotes_are_not_stripped(): void
    {
        // Regression: trim($value, "\"'") stripped mismatched quotes from
        // either end ("foo' became foo), mangling values that legitimately
        // begin or end with a quote character.
        $env = $this->writeEnv("MIXED=\"foo'\nMIXED2='bar\"\nOPEN=\"unterminated\nTRAIL=trailing'\n");
        $this->assertSame("\"foo'", $env->get('MIXED'));
        $this->assertSame("'bar\"", $env->get('MIXED2'));
        $this->assertSame('"unterminated', $env->get('OPEN'));
        $this->assertSame("trailing'", $env->get('TRAIL'));
    }

    public function test_value_that_is_only_a_comment_becomes_empty_string(): void
    {
        $env = $this->writeEnv("APP_KEY= # generate me\nBARE=#no space before hash\n");
        $this->assertSame('', $env->get('APP_KEY'));
        $this->assertSame('', $env->get('BARE'));
        $this->assertTrue($env->has('APP_KEY'), 'key is set; its value is just empty');
    }

    public function test_skips_comments_blank_and_malformed_lines_without_warning(): void
    {
        // failOnWarning="true" in phpunit.xml makes a PHP warning fail this test,
        // so this asserts the malformed line ("GARBAGE") is skipped cleanly.
        $env = $this->writeEnv("# a comment\n\nGARBAGE\nAPP_ENV=production\n");
        $this->assertSame('production', $env->get('APP_ENV'));
        $this->assertFalse($env->has('GARBAGE'));
    }

    public function test_returns_default_for_missing_key(): void
    {
        $env = $this->writeEnv("APP_NAME=hydra\n");
        $this->assertSame('fallback', $env->get('NOPE', 'fallback'));
        $this->assertNull($env->get('NOPE'));
    }

    public function test_has_reflects_presence(): void
    {
        $env = $this->writeEnv("PRESENT=1\n");
        $this->assertTrue($env->has('PRESENT'));
        $this->assertFalse($env->has('ABSENT'));
    }

    public function test_real_environment_beats_dot_env_file(): void
    {
        // A variable the process already has (container runtime, web server,
        // an `export`) must override the .env file's value, and the file's
        // value must not be exported over it either.
        putenv('HYDRA_TEST_REAL=from-process');
        $_ENV['HYDRA_TEST_REAL'] = 'from-process';

        try {
            $env = $this->writeEnv("HYDRA_TEST_REAL=from-file\n");

            $this->assertSame('from-process', $env->get('HYDRA_TEST_REAL'));
            $this->assertSame('from-process', getenv('HYDRA_TEST_REAL'));
            $this->assertSame('from-process', $_ENV['HYDRA_TEST_REAL']);
        } finally {
            putenv('HYDRA_TEST_REAL');
            unset($_ENV['HYDRA_TEST_REAL'], $_SERVER['HYDRA_TEST_REAL']);
        }
    }

    public function test_variable_set_via_putenv_alone_still_beats_dot_env(): void
    {
        // getenv()-only variables (no $_ENV mirror) count as the real
        // environment too.
        putenv('HYDRA_TEST_GETENV=os-level');

        try {
            $env = $this->writeEnv("HYDRA_TEST_GETENV=from-file\n");

            $this->assertSame('os-level', $env->get('HYDRA_TEST_GETENV'));
        } finally {
            putenv('HYDRA_TEST_GETENV');
        }
    }

    public function test_dot_env_value_is_exported_when_process_has_no_value(): void
    {
        $env = $this->writeEnv("HYDRA_TEST_EXPORT=filled-from-file\n");

        try {
            $this->assertSame('filled-from-file', $env->get('HYDRA_TEST_EXPORT'));
            $this->assertSame('filled-from-file', getenv('HYDRA_TEST_EXPORT'));
            $this->assertSame('filled-from-file', $_ENV['HYDRA_TEST_EXPORT']);
        } finally {
            putenv('HYDRA_TEST_EXPORT');
            unset($_ENV['HYDRA_TEST_EXPORT']);
        }
    }

    public function test_required_returns_the_value(): void
    {
        $env = $this->writeEnv("NEEDED_SECRET=s3cret\n");
        $this->assertSame('s3cret', $env->required('NEEDED_SECRET'));
    }

    public function test_required_throws_naming_the_missing_key(): void
    {
        $env = $this->writeEnv("APP_NAME=hydra\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required environment variable "TOTALLY_MISSING" is not set');

        $env->required('TOTALLY_MISSING');
    }

    public function test_required_throws_on_empty_value(): void
    {
        // `KEY=` in the file is set-but-empty; for required config that is
        // the same misconfiguration as unset.
        $env = $this->writeEnv("EMPTY_REQUIRED=\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required environment variable "EMPTY_REQUIRED" is not set');

        $env->required('EMPTY_REQUIRED');
    }

    public function test_bool_accepted_forms(): void
    {
        // The documented contract, case-insensitively and nothing else:
        // true/false, 1/0, yes/no, on/off.
        $env = $this->writeEnv(
            "BT1=true\nBT2=1\nBT3=yes\nBT4=on\nBT5=TRUE\nBT6=Yes\n" .
            "BF1=false\nBF2=0\nBF3=no\nBF4=off\nBF5=FALSE\nBF6=Off\n"
        );

        foreach (['BT1', 'BT2', 'BT3', 'BT4', 'BT5', 'BT6'] as $key) {
            $this->assertTrue($env->bool($key), "{$key} should parse as true");
        }
        foreach (['BF1', 'BF2', 'BF3', 'BF4', 'BF5', 'BF6'] as $key) {
            $this->assertFalse($env->bool($key), "{$key} should parse as false");
        }

        $this->assertTrue($env->bool('MISSING_BOOL', true), 'missing key returns the default');
        $this->assertFalse($env->bool('MISSING_BOOL'));
    }

    public function test_bool_rejects_garbage(): void
    {
        // A present-but-non-boolean value is a config error, not a silent
        // false. Same policy as int().
        $env = $this->writeEnv("FLAGGY=anything\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment value for "FLAGGY" must be a boolean');

        $env->bool('FLAGGY');
    }

    public function test_bool_rejects_empty_string(): void
    {
        $env = $this->writeEnv("EMPTY_FLAG=\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment value for "EMPTY_FLAG" must be a boolean');

        $env->bool('EMPTY_FLAG');
    }

    public function test_int_coercion(): void
    {
        $env = $this->writeEnv("PORT=8080\nZERO=0\nNEG=-5\n");
        $this->assertSame(8080, $env->int('PORT'));
        $this->assertSame(0, $env->int('ZERO'), 'a literal 0 is a valid int, not "missing"');
        $this->assertSame(-5, $env->int('NEG'));
        $this->assertSame(42, $env->int('MISSING', 42));
    }

    public function test_int_rejects_non_numeric_value(): void
    {
        // A present-but-non-integer value is a config error, not a silent 0.
        $env = $this->writeEnv("PORT=abc\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment value for "PORT" must be an integer');

        $env->int('PORT');
    }

    public function test_list_splits_on_commas_and_trims(): void
    {
        $env = $this->writeEnv("TRUSTED_PROXIES=10.0.0.0/8, 172.18.0.0/16 ,2001:db8::/32\n");

        $this->assertSame(
            ['10.0.0.0/8', '172.18.0.0/16', '2001:db8::/32'],
            $env->list('TRUSTED_PROXIES'),
        );
    }

    public function test_list_treats_missing_and_empty_alike(): void
    {
        // "not configured" and "configured to nothing" mean the same thing for
        // a list, and a caller should not have to tell them apart.
        $env = $this->writeEnv("TRUSTED_PROXIES=\n");

        $this->assertSame([], $env->list('TRUSTED_PROXIES'));
        $this->assertSame([], $env->list('NOT_SET_AT_ALL'));
        $this->assertSame(['127.0.0.1'], $env->list('NOT_SET_AT_ALL', ['127.0.0.1']));
    }

    public function test_list_drops_blank_entries(): void
    {
        // A trailing comma is the most common way to write this by hand.
        $env = $this->writeEnv("HOSTS=a,,b,\n");

        $this->assertSame(['a', 'b'], $env->list('HOSTS'));
    }

    public function test_single_value_is_a_list_of_one(): void
    {
        $env = $this->writeEnv("HOSTS=10.0.0.1\n");

        $this->assertSame(['10.0.0.1'], $env->list('HOSTS'));
    }

    public function test_missing_env_file_does_not_error(): void
    {
        // No .env written: load() must no-op silently.
        $env = new Environment($this->dir);
        $this->assertSame('d', $env->get('ANYTHING', 'd'));
    }
}
