<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Profiles;

use PDO;
use PeakSim\Runtime\Measure\Profile;
use PHPUnit\Framework\TestCase;

/**
 * The profile for one real app (Bagisto), as data: no Docker and no Bagisto needed here. It is checked for shape (the
 * loader accepts it), for the declarations that are plain data, and for what its `after_generation` refuses. That the
 * declarations are true of the real schema is shown by the measurement run that uses them.
 */
final class BagistoProfileTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $profile;

    protected function setUp(): void
    {
        $this->profile = Profile::load('bagisto');
    }

    public function test_the_loader_accepts_it(): void
    {
        $this->assertIsCallable($this->profile['after_generation']);
    }

    public function test_the_reference_tables_stay_small_however_large_the_shop_is(): void
    {
        $this->assertSame(1, $this->profile['rows']['channels']);
        $this->assertSame(1, $this->profile['rows']['locales']);
        $this->assertSame(1, $this->profile['rows']['currencies']);
        $this->assertSame(3, $this->profile['rows']['customer_groups']);
    }

    public function test_every_fixup_says_why_and_names_the_line_in_the_app(): void
    {
        $names = [];
        foreach ($this->profile['fixups'] as $fix) {
            $this->assertNotSame('', $fix['why'], $fix['name']);
            $this->assertMatchesRegularExpression('/\.php:\d+/', $fix['why'], 'fixup ' . $fix['name'] . ' names the line in the app');
            $names[] = $fix['name'];
        }
        $this->assertSame(array_values(array_unique($names)), $names, 'a fixup name is used once');
    }

    public function test_every_fixup_is_one_statement_that_writes_only_a_data_table(): void
    {
        foreach ($this->profile['fixups'] as $fix) {
            $this->assertMatchesRegularExpression('/^(UPDATE [a-z_]+ SET |INSERT INTO [a-z_]+ \(|DELETE FROM [a-z_]+)/', $fix['sql'], $fix['name']);
            $this->assertStringNotContainsString(';', $fix['sql'], $fix['name']);
            $this->assertDoesNotMatchRegularExpression('/\b(DROP|ALTER|TRUNCATE|GRANT|CREATE)\b/i', $fix['sql'], $fix['name']);
        }
    }

    public function test_every_table_a_fixup_inserts_into_is_left_empty_by_the_generator(): void
    {
        foreach ($this->profile['fixups'] as $fix) {
            if (preg_match('/^INSERT INTO ([a-z_]+) /', $fix['sql'], $m) === 1) {
                $this->assertContains($m[1], $this->profile['skip'], $fix['name'] . ' inserts into a table the generator also fills');
            }
        }
    }

    public function test_the_attribute_rows_the_fixup_names_are_as_many_as_the_rows_it_rewrites(): void
    {
        // the migration makes row 1 (`manage_stock`); the generated rows 2 to 30 take the other 29 definitions
        $this->assertSame(29, $this->profile['rows']['attributes']);
        $sql = $this->fixup('attributes_are_the_defined_ones');
        preg_match_all("/ELT\(id - 1, ((?:'[a-z_]+'(?:, )?)+)\)/", $sql, $lists);
        $this->assertCount(2, $lists[1], 'one list of codes and one of types');
        foreach ($lists[1] as $list) {
            $this->assertCount(29, explode(', ', $list));
        }
        $this->assertStringContainsString('WHERE id BETWEEN 2 AND 30', $sql);
    }

    public function test_the_categories_become_a_nested_set_under_the_root(): void
    {
        // the fixup, run on a small table: the root holds every other row between its numbers and no two numbers repeat
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, parent_id INTEGER, _lft INTEGER, _rgt INTEGER, status INTEGER, position INTEGER, display_mode TEXT)');
        for ($i = 1; $i <= 6; ++$i) {
            $pdo->exec("INSERT INTO categories (id, parent_id, _lft, _rgt) VALUES ($i, $i, 0, 0)");
        }
        // SQLite spells MySQL's IF() as IIF(); nothing else of the statement differs
        $pdo->exec(str_replace('IF(', 'IIF(', $this->fixup('categories_are_a_tree_under_the_root')));

        $this->assertSame([1, 12], array_map('intval', $pdo->query('SELECT _lft, _rgt FROM categories WHERE id = 1')->fetch(PDO::FETCH_NUM)));
        $numbers = $pdo->query('SELECT _lft FROM categories UNION ALL SELECT _rgt FROM categories ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(range(1, 12), array_map('intval', $numbers), 'the numbers 1 to 2n each appear once');
        $this->assertSame(5, (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE parent_id = 1 AND id > 1')->fetchColumn());
    }

    public function test_after_generation_refuses_a_database_that_is_not_a_measurement_database(): void
    {
        $this->expectException(\Throwable::class);
        ($this->profile['after_generation'])(new PDO('sqlite::memory:'), 'production');
    }

    private function fixup(string $name): string
    {
        foreach ($this->profile['fixups'] as $fix) {
            if ($fix['name'] === $name) {
                return $fix['sql'];
            }
        }
        $this->fail('no fixup named ' . $name);
    }
}
