<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Profiles;

use PDO;
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

    public function test_a_plan_that_grows_only_the_entities_keeps_one_row_per_entity_and_role(): void
    {
        // the targeted plan of one table: roles stay at their nine (the four the migrations make and the five of the
        // base size), so each of the 200 entities has nine rows, as the app's own builder writes
        $pdo = $this->database(roles: 9, entities: 200);

        $pdo->exec($this->fixup(self::FIXUP));

        $this->assertSame(200 * 9, $this->rowCount($pdo));
    }

    public function test_a_plan_that_grows_only_the_roles_keeps_one_row_per_role_for_each_base_entity(): void
    {
        // the targeted plan of the roles table: the five entities of the base size, each with a row for every one of
        // the 200 roles
        $pdo = $this->database(roles: 200, entities: 5);

        $pdo->exec($this->fixup(self::FIXUP));

        $this->assertSame(5 * 200, $this->rowCount($pdo));
    }

    public function test_a_plan_that_grows_every_table_does_not_grow_with_the_square_of_the_scale(): void
    {
        // Roles and entities both grow with N, and one row per entity and role is N*N rows: 100 million at N = 10000,
        // a statement the database kept running for hours after the measurer's own limit had killed the process that
        // sent it. Hand-counted: the rows with a role of the nine lowest numbers or an entity of the five lowest.
        // N = 100: 9*100 + 5*104 - 9*5 = 1375;  N = 1000: 9*1000 + 5*1004 - 9*5 = 13975.
        $small = $this->database(roles: 104, entities: 100);
        $small->exec($this->fixup(self::FIXUP));
        $large = $this->database(roles: 1004, entities: 1000);
        $large->exec($this->fixup(self::FIXUP));

        $this->assertSame(1375, $this->rowCount($small));
        $this->assertSame(13975, $this->rowCount($large));
        $this->assertLessThan(11 * $this->rowCount($small), $this->rowCount($large), 'ten times the scale must be about ten times the rows');
    }

    public function test_the_admin_role_of_the_migrations_has_a_row_for_every_entity_in_each_shape(): void
    {
        // the login holds the admin role (id 1), so every entity must stay visible to it
        foreach ([[9, 200], [200, 5], [104, 100], [3, 100]] as [$roles, $entities]) {
            $pdo = $this->database(roles: $roles, entities: $entities);

            $pdo->exec($this->fixup(self::FIXUP));

            $this->assertSame(
                $entities,
                (int) $pdo->query('SELECT COUNT(DISTINCT entity_id) FROM joint_permissions WHERE role_id = 1')->fetchColumn(),
                sprintf('roles %d, entities %d', $roles, $entities),
            );
        }
    }

    public function test_with_fewer_roles_than_the_bound_every_role_still_gets_a_row_per_entity(): void
    {
        $pdo = $this->database(roles: 3, entities: 100);

        $pdo->exec($this->fixup(self::FIXUP));

        $this->assertSame(100 * 3, $this->rowCount($pdo));
    }

    public function test_the_fixup_still_says_why_and_names_the_bound(): void
    {
        $why = $this->fixupEntry(self::FIXUP)['why'];

        $this->assertMatchesRegularExpression('/\.php:\d+/', $why);
        $this->assertStringContainsString('N*N', $why);
        $this->assertStringContainsString('from 1', $why, 'the bound reads the numbers as running from 1');
    }

    private function rowCount(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM joint_permissions')->fetchColumn();
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
