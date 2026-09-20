<?php

declare(strict_types=1);

namespace Hydra\Database\Tests\Unit;

use Hydra\Database\PdoConnection;
use Hydra\Database\Validation\Exists;
use Hydra\Database\Validation\Identifier;
use Hydra\Database\Validation\Unique;
use Hydra\Validation\Context;
use Hydra\Validation\Validator;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * The two rules that ask the database a question, over a real sqlite
 * connection: what they do with a value that cannot name a row, and what they
 * refuse to interpolate into the SQL.
 */
#[CoversTrait(Identifier::class)]
#[CoversClass(Exists::class)]
#[CoversClass(Unique::class)]
final class DatabaseRulesTest extends TestCase
{
    private PdoConnection $db;
    private Context $context;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->db = new PdoConnection($pdo);
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
        $this->db->execute("INSERT INTO users (id, email) VALUES (1, 'ada@example.com'), (2, 'grace@example.com')");
        $this->context = new Context([], 'email');
    }

    public function test_exists_finds_the_row_or_reports_the_selection_is_gone(): void
    {
        $rule = new Exists($this->db, 'users', 'id');

        $this->assertNull($rule->validate(1, $this->context));
        $this->assertNull($rule->validate('1', $this->context));
        $this->assertSame('That selection is not available.', $rule->validate(99, $this->context));
    }

    public function test_exists_refuses_a_value_that_could_never_name_a_row(): void
    {
        // A foreign key arriving as `category[]=1` must fail the lookup, not
        // reach the driver as an array.
        $rule = new Exists($this->db, 'users', 'id');

        $this->assertNotNull($rule->validate(['1'], $this->context));
        $this->assertNotNull($rule->validate(null, $this->context));
    }

    public function test_unique_passes_only_when_nothing_holds_the_value(): void
    {
        $rule = new Unique($this->db, 'users', 'email');

        $this->assertNull($rule->validate('new@example.com', $this->context));
        $this->assertSame('That value is already taken.', $rule->validate('ada@example.com', $this->context));
    }

    public function test_unique_ignores_the_row_being_edited(): void
    {
        // Without this an edit form always fails against the row it is editing.
        $editing = new Unique($this->db, 'users', 'email', ignore: 1);

        $this->assertNull($editing->validate('ada@example.com', $this->context));
        $this->assertNotNull($editing->validate('grace@example.com', $this->context));
    }

    public function test_unique_can_ignore_on_a_column_other_than_id(): void
    {
        $rule = new Unique($this->db, 'users', 'email', ignore: 'ada@example.com', ignoreColumn: 'email');

        $this->assertNull($rule->validate('ada@example.com', $this->context));
    }

    public function test_unique_cannot_prove_a_duplicate_of_a_value_that_is_not_scalar(): void
    {
        // The type rules own that complaint; claiming "already taken" here
        // would put a misleading message under the input.
        $this->assertNull((new Unique($this->db, 'users', 'email'))->validate(['a'], $this->context));
    }

    public function test_both_rules_work_through_the_validator(): void
    {
        $result = (new Validator)->validate(
            ['email' => 'ada@example.com', 'author_id' => 99],
            [
                'email' => [new Unique($this->db, 'users', 'email')],
                'author_id' => [new Exists($this->db, 'users', 'id')],
            ],
        );

        $this->assertSame(
            ['email' => 'That value is already taken.', 'author_id' => 'That selection is not available.'],
            $result->errors(),
        );
    }

    public function test_a_table_name_that_is_not_an_identifier_is_refused_at_construction(): void
    {
        // Neither name can travel as a bound parameter, so the rules take an
        // identifier and nothing else rather than escaping for a dialect they
        // were not told about.
        $this->expectException(InvalidArgumentException::class);

        new Exists($this->db, 'users; DROP TABLE users', 'id');
    }

    public function test_a_column_name_that_is_not_an_identifier_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Unique($this->db, 'users', 'email = 1 OR 1');
    }

    public function test_the_ignore_column_is_checked_as_well(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Unique($this->db, 'users', 'email', ignore: 1, ignoreColumn: 'id) OR (1');
    }
}
