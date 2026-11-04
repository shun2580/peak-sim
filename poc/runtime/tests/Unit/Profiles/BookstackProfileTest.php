<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Profiles;

use PDO;
use PeakSim\Runtime\Measure\GeneratorPlanner;
use PeakSim\Runtime\Measure\Profile;
use PHPUnit\Framework\TestCase;

/**
 * The profile for BookStack, as data. Only what can be shown without Docker and without BookStack: the loader accepts
 * it, and the one fixup whose cost depends on the size of two tables is bounded.
 */
final class BookstackProfileTest extends TestCase
{
    private const FIXUP = 'joint_permissions_for_every_entity_and_role';

    /** @var array<string, mixed> */
    private array $profile;

    protected function setUp(): void
    {
        $this->profile = Profile::load('bookstack');
    }

    public function test_the_loader_accepts_it(): void
    {
        $this->assertIsCallable($this->profile['after_generation']);
    }

    public function test_the_permission_rows_do_not_grow_with_the_square_of_the_scale(): void
    {
        // Under a plan that grows every table, roles and entities are both N, and one row per entity and role is N*N
        // rows: 100 million at N = 10000, a statement the database kept running for hours after the measurer's own
        // limit had killed the process that sent it.
        $pdo = $this->database(roles: 40, entities: 7);

        $pdo->exec($this->fixup(self::FIXUP));

        $this->assertSame(7 * GeneratorPlanner::BASE, (int) $pdo->query('SELECT COUNT(*) FROM joint_permissions')->fetchColumn());
    }

    public function test_the_permission_rows_name_the_lowest_roles_and_so_the_admin_role_of_the_migrations(): void
    {
        $pdo = $this->database(roles: 40, entities: 3);

        $pdo->exec($this->fixup(self::FIXUP));

        $roles = $pdo->query('SELECT DISTINCT role_id FROM joint_permissions ORDER BY role_id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(range(1, GeneratorPlanner::BASE), array_map('intval', $roles));
    }

    public function test_with_fewer_roles_than_the_bound_every_role_still_gets_a_row_per_entity(): void
    {
        // the targeted plans leave roles at the base size (and fewer, where a plan says so): nothing is lost there
        $pdo = $this->database(roles: 3, entities: 100);

        $pdo->exec($this->fixup(self::FIXUP));

        $this->assertSame(100 * 3, (int) $pdo->query('SELECT COUNT(*) FROM joint_permissions')->fetchColumn());
    }

    public function test_the_fixup_still_says_why_and_names_the_bound(): void
    {
        $why = $this->fixupEntry(self::FIXUP)['why'];

        $this->assertMatchesRegularExpression('/\.php:\d+/', $why);
        $this->assertStringContainsString('N*N', $why);
    }

    private function database(int $roles, int $entities): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE entities (id INTEGER PRIMARY KEY, type TEXT)');
        $pdo->exec('CREATE TABLE joint_permissions (role_id INTEGER, entity_type TEXT, entity_id INTEGER, status INTEGER, owner_id INTEGER)');
        for ($i = 1; $i <= $roles; ++$i) {
            $pdo->exec('INSERT INTO roles (id) VALUES (' . $i . ')');
        }
        for ($i = 1; $i <= $entities; ++$i) {
            $pdo->exec("INSERT INTO entities (id, type) VALUES ($i, 'page')");
        }

        return $pdo;
    }

    private function fixup(string $name): string
    {
        return $this->fixupEntry($name)['sql'];
    }

    /** @return array{name: string, why: string, sql: string} */
    private function fixupEntry(string $name): array
    {
        foreach ($this->profile['fixups'] as $fix) {
            if ($fix['name'] === $name) {
                return $fix;
            }
        }
        $this->fail('no fixup named ' . $name);
    }
}
