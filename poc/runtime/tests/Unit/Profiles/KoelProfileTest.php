<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Profiles;

use PDO;
use PeakSim\Runtime\Run\Classification;
use PHPUnit\Framework\TestCase;

/**
 * The profile for one real app (Koel), as data: no Docker and no Koel needed here. What it declares is checked for
 * shape and for what its own `after_generation` does to a small stand-in database. That the declarations are true of
 * Koel's real schema is checked by the Docker test beside this suite.
 */
final class KoelProfileTest extends TestCase
{
    private const FILE = __DIR__ . '/../../../profiles/koel.php';

    private const DATABASE = 'peak_sim_measure_0123456789ab_100';

    /** Every key the profile may have. The first five are read by the driver today; the rest are described in the file. */
    private const KEYS = ['skip', 'rows', 'values', 'user', 'login_as', 'admin', 'reference_rows', 'role_permissions', 'fixups', 'arguments', 'unfillable', 'after_generation'];

    /** Why a route cannot be hit: the fixed vocabulary of the design. */
    private const REASONS = ['unfilled_param', 'unauthenticated', 'forbidden', 'not_found', 'signature_required', 'external_call_blocked', 'file_dependency', 'validation_422', 'rate_limited'];

    /** @return array<string, mixed> */
    private static function profile(): array
    {
        self::assertFileExists(self::FILE);
        $profile = (static fn (): mixed => require self::FILE)();
        self::assertIsArray($profile);

        return $profile;
    }

    public function test_the_file_says_it_is_app_knowledge_and_that_a_general_form_is_designed_apart(): void
    {
        $source = (string) file_get_contents(self::FILE);
        $head = substr($source, 0, (int) strpos($source, 'return ['));

        $this->assertStringContainsString('Koel', $head);
        $this->assertMatchesRegularExpression('/knowledge about (the|this) app/i', $head);
        $this->assertMatchesRegularExpression('/not (a|the) (judging )?rule/i', $head, 'the profile is knowledge, never a rule of judgement');
        $this->assertMatchesRegularExpression('/(general|generalis|generaliz)\w*.*(designed|design) (apart|separately)|(designed|design) (apart|separately).*(general)/is', $head, 'a permanent version designs the general form on its own');
    }

    public function test_it_has_no_absolute_path_user_name_or_secret(): void
    {
        $source = (string) file_get_contents(self::FILE);

        $this->assertSame(0, preg_match('~/home/|/Users/|/mnt/|nishikawa|tono~', $source));
        $this->assertSame(0, preg_match('/(secret|token|api[_-]?key)\s*=>\s*[\'"][^\'"]{12,}/i', $source), 'no credential-looking value');
    }

    public function test_it_only_has_keys_that_are_described(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(self::profile()), self::KEYS)));
        foreach (['skip', 'rows', 'values', 'user', 'admin', 'reference_rows', 'fixups', 'arguments', 'unfillable', 'after_generation'] as $key) {
            $this->assertArrayHasKey($key, self::profile(), $key);
        }
    }

    public function test_the_generator_keys_are_in_the_form_the_generator_reads(): void
    {
        $p = self::profile();

        $this->assertSame(array_values(array_unique($p['skip'])), $p['skip'], 'no table twice');
        foreach ($p['skip'] as $table) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $table);
        }
        foreach ($p['rows'] as $table => $n) {
            $this->assertIsString($table);
            $this->assertIsInt($n);
            $this->assertGreaterThanOrEqual(1, $n);
        }
        foreach ($p['values'] as $table => $columns) {
            $this->assertIsArray($columns, $table);
            foreach ($columns as $column => $value) {
                $this->assertTrue($value === null || is_scalar($value), "$table.$column is a constant");
            }
        }
    }

    public function test_the_tables_the_migrations_and_this_profile_fill_by_hand_are_left_to_them(): void
    {
        $skip = self::profile()['skip'];

        foreach (['roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'settings', 'licenses'] as $table) {
            $this->assertContains($table, $skip, "a generated row in $table would only be noise (or wrong)");
        }
        $this->assertNotContains('users', $skip);
        $this->assertNotContains('songs', $skip);
    }

    public function test_one_default_organization_and_the_columns_that_cannot_hold_generated_text_are_empty(): void
    {
        $p = self::profile();

        $this->assertSame(1, $p['rows']['organizations'] ?? null, 'users are moved into the one default organization');
        foreach (['subsonic_api_key', 'subsonic_api_key_hash', 'two_factor_secret', 'two_factor_recovery_codes', 'invitation_token', 'invited_at', 'deletion_requested_at'] as $column) {
            $this->assertArrayHasKey($column, $p['values']['users'], $column);
            $this->assertNull($p['values']['users'][$column], "$column holds an encrypted or one-off value: NULL");
        }
    }

    public function test_the_columns_the_app_reads_as_data_hold_data_and_membership_means_ownership(): void
    {
        $values = self::profile()['values'];

        $this->assertSame('[]', $values['queue_states']['song_ids'], 'the app reads the queue as a JSON list; a generated word breaks the first page load');
        $this->assertSame('owner', $values['playlist_user']['role'], 'a playlist listed for its user needs the user to be its owner');
        $this->assertSame(26, strlen(self::profile()['reference_rows'][0]['row']['id']), 'an organization key is 26 characters');
    }

    public function test_two_logins_are_declared_a_general_user_and_an_admin(): void
    {
        $p = self::profile();

        $this->assertSame('user', $p['user']['role']);
        $this->assertSame('admin', $p['admin']['role']);
        $this->assertNotSame($p['user']['id'], $p['admin']['id']);
        $this->assertNotSame($p['user']['email'], $p['admin']['email']);
        foreach (['user', 'admin'] as $who) {
            $this->assertIsInt($p[$who]['id']);
            $this->assertGreaterThanOrEqual(1, $p[$who]['id']);
            $this->assertMatchesRegularExpression('/^[a-z0-9.+-]+@[a-z0-9.-]+\.[a-z]+$/', $p[$who]['email']);
            $this->assertStringEndsWith('.invalid', $p[$who]['email'], 'a synthetic address that can reach no one');
        }
        $this->assertContains($p['login_as'] ?? 'user', ['user', 'admin'], 'login_as picks which of the two the first request uses');
    }

    public function test_the_reference_rows_cover_the_organization_the_roles_the_permissions_and_the_setting(): void
    {
        $rows = self::profile()['reference_rows'];

        $byTable = [];
        foreach ($rows as $entry) {
            $this->assertArrayHasKey('table', $entry);
            $this->assertNotSame([], $entry['match'], 'each entry says how to tell the row is already there');
            $this->assertNotSame([], $entry['row']);
            foreach ($entry['match'] as $column => $value) {
                $this->assertArrayHasKey($column, $entry['row'], 'the match is part of the row');
                $this->assertSame($value, $entry['row'][$column]);
            }
            $byTable[$entry['table']][] = $entry['row'];
        }

        $this->assertSame(['koel'], array_column($byTable['organizations'], 'slug'));
        $this->assertSame(['admin', 'manager', 'user', 'guest'], array_column($byTable['roles'], 'name'));
        $this->assertEqualsCanonicalizing(['manage settings', 'manage users', 'manage songs', 'manage radio stations', 'manage podcasts'], array_column($byTable['permissions'], 'name'));
        $this->assertSame(['media_path'], array_column($byTable['settings'], 'key'));
        $this->assertArrayNotHasKey('licenses', $byTable, 'the community edition has no licence row; checking one would call out');
    }

    public function test_each_role_lists_the_permissions_it_holds(): void
    {
        $perm = self::profile()['role_permissions'];

        $this->assertEqualsCanonicalizing(['manage settings', 'manage users', 'manage songs', 'manage radio stations', 'manage podcasts'], $perm['admin']);
        $this->assertEqualsCanonicalizing(['manage users', 'manage podcasts', 'manage songs', 'manage radio stations'], $perm['manager']);
        $this->assertSame([], $perm['user']);
    }

    public function test_the_fixups_are_single_updates_and_say_why(): void
    {
        $fixups = self::profile()['fixups'];

        $this->assertNotSame([], $fixups);
        foreach ($fixups as $fix) {
            $this->assertNotSame('', $fix['name']);
            $this->assertNotSame('', $fix['why']);
            $this->assertMatchesRegularExpression('/^UPDATE `?[a-z_]+`? SET /', $fix['sql']);
            $this->assertStringNotContainsString(';', $fix['sql'], 'one statement');
            $this->assertSame(0, preg_match('/\b(DROP|ALTER|TRUNCATE|CREATE|RENAME|GRANT|LOCK|COMMIT|BEGIN|DELETE)\b/i', $fix['sql']), 'a fixup cannot be a statement that commits or removes');
        }
        $this->assertContains('songs_are_not_episodes', array_column($fixups, 'name'));
    }

    public function test_arguments_say_where_a_value_comes_from_and_unfillable_gives_a_reason_from_the_vocabulary(): void
    {
        $p = self::profile();

        $this->assertNotSame([], $p['arguments']);
        foreach ($p['arguments'] as $param => $how) {
            $this->assertMatchesRegularExpression('/^[A-Za-z_]+$/', $param);
            $this->assertTrue(isset($how['table'], $how['column']) || array_key_exists('value', $how), "$param: a column of a table, or a literal value");
            $this->assertNotSame('', $how['why'], $param);
        }
        $this->assertNotSame([], $p['unfillable']);
        foreach ($p['unfillable'] as $what => $entry) {
            $this->assertContains($entry['reason'], self::REASONS, $what);
            $this->assertNotSame('', $entry['why'], $what);
        }
        $this->assertTrue(class_exists(Classification::class), 'the vocabulary is the design\'s, not a new one');
    }

    // ---- after_generation, on a stand-in database ------------------------------------------------------------------

    private static function standIn(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE organizations (id TEXT PRIMARY KEY, name TEXT, slug TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, guard_name TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, guard_name TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE role_has_permissions (permission_id INTEGER, role_id INTEGER)');
        $pdo->exec('CREATE TABLE model_has_roles (role_id INTEGER, model_type TEXT, model_id INTEGER)');
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id TEXT, "key" TEXT, value TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, organization_id TEXT, name TEXT, email TEXT, password TEXT, preferences TEXT)');
        $pdo->exec('CREATE TABLE songs (id TEXT PRIMARY KEY, podcast_id TEXT, episode_guid TEXT, title TEXT)');
        // what the generator leaves behind: a user in a generated organization, songs that all look like episodes
        $pdo->exec("INSERT INTO organizations (id, name, slug) VALUES ('generated-org', 'organizations_name_1', 'organizations_slug_1')");
        foreach ([1, 2, 3] as $id) {
            $pdo->exec("INSERT INTO users (id, organization_id, name, email, password, preferences) VALUES ($id, 'generated-org', 'users_name_$id', 'users_email_$id', 'x', '{}')");
            $pdo->exec("INSERT INTO songs (id, podcast_id, episode_guid, title) VALUES ('s$id', 'p$id', 'g$id', 't$id')");
        }

        return $pdo;
    }

    /** @return array<string, mixed> */
    private static function apply(PDO $pdo): array
    {
        $summary = (self::profile()['after_generation'])($pdo, self::DATABASE);
        self::assertIsArray($summary);

        return $summary;
    }

    private static function scalar(PDO $pdo, string $sql): mixed
    {
        $value = $pdo->query($sql)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function test_after_generation_adds_the_reference_rows_once_and_keeps_a_row_a_migration_made(): void
    {
        $pdo = self::standIn();
        $pdo->exec("INSERT INTO roles (name, guard_name) VALUES ('admin', 'api')"); // a migration made this one

        self::apply($pdo);
        self::apply($pdo);

        $this->assertSame(4, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM roles'));
        $this->assertSame(1, (int) self::scalar($pdo, "SELECT COUNT(*) FROM roles WHERE name = 'admin'"));
        $this->assertSame(5, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM permissions'));
        $this->assertSame(1, (int) self::scalar($pdo, "SELECT COUNT(*) FROM settings WHERE \"key\" = 'media_path'"));
        $this->assertSame(1, (int) self::scalar($pdo, "SELECT COUNT(*) FROM organizations WHERE slug = 'koel'"));
        $this->assertSame(5 + 4, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM role_has_permissions'), 'admin 5 + manager 4, no duplicates after two runs');
    }

    public function test_after_generation_moves_both_logins_into_the_default_organization_and_gives_each_its_role(): void
    {
        $pdo = self::standIn();
        $p = self::profile();

        self::apply($pdo);

        $org = self::scalar($pdo, "SELECT id FROM organizations WHERE slug = 'koel'");
        foreach (['user' => 'user', 'admin' => 'admin'] as $who => $role) {
            $id = $p[$who]['id'];
            $this->assertSame($org, self::scalar($pdo, "SELECT organization_id FROM users WHERE id = $id"), $who);
            $this->assertSame($p[$who]['email'], self::scalar($pdo, "SELECT email FROM users WHERE id = $id"));
            $this->assertTrue(password_verify($p[$who]['password'], (string) self::scalar($pdo, "SELECT password FROM users WHERE id = $id")), "$who can log in with the declared password");
            $this->assertSame($role, self::scalar($pdo, "SELECT r.name FROM model_has_roles m JOIN roles r ON r.id = m.role_id WHERE m.model_id = $id AND m.model_type = 'App\\Models\\User'"), $who);
        }
        $this->assertSame(2, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM model_has_roles'), 'only the two logins hold a role');
    }

    public function test_after_generation_runs_the_fixups_and_reports_what_each_changed(): void
    {
        $pdo = self::standIn();

        $summary = self::apply($pdo);

        $this->assertSame(0, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM songs WHERE podcast_id IS NOT NULL OR episode_guid IS NOT NULL'));
        $this->assertSame(3, $summary['fixups']['songs_are_not_episodes']);
    }

    public function test_after_generation_refuses_a_connection_that_is_not_a_measurement_database(): void
    {
        $pdo = self::standIn();

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/measurement database/i');
        (self::profile()['after_generation'])($pdo, 'app');
    }
}
