<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Profiles;

use PDO;
use PeakSim\Runtime\Measure\Profile;
use PHPUnit\Framework\TestCase;

/**
 * The second Koel profile: everything the first one says, plus a pointing of the generated rows at the login. It is
 * data, so no Docker and no Koel are needed here; what it does is checked on a small stand-in database. That the
 * statements fit Koel's real schema is checked by a measurement run.
 */
final class KoelUserboundProfileTest extends TestCase
{
    private const NAME = 'koel_userbound';

    private const FILE = __DIR__ . '/../../../profiles/koel_userbound.php';

    private const DATABASE = 'peak_sim_measure_0123456789ab_100';

    /** Keys of the first profile that this one keeps as they are. */
    private const KEPT = ['skip', 'rows', 'values', 'user', 'admin', 'login_as', 'reference_rows', 'role_permissions', 'arguments', 'unfillable', 'request_query'];

    private string|false $savedKey = false;

    protected function setUp(): void
    {
        $this->savedKey = getenv('PEAK_MEASURE_APP_KEY');
        putenv('PEAK_MEASURE_APP_KEY');
    }

    protected function tearDown(): void
    {
        putenv($this->savedKey === false ? 'PEAK_MEASURE_APP_KEY' : 'PEAK_MEASURE_APP_KEY=' . $this->savedKey);
    }

    /** @return array<string, mixed> */
    private static function profile(): array
    {
        return Profile::load(self::NAME);
    }

    /** @return array<string, mixed> */
    private static function base(): array
    {
        return Profile::load('koel');
    }

    public function test_the_loader_accepts_it_by_name(): void
    {
        $this->assertFileExists(self::FILE);
        $this->assertNotSame([], self::profile());
    }

    public function test_it_keeps_what_the_first_profile_says_about_the_app(): void
    {
        $base = self::base();
        $p = self::profile();

        foreach (self::KEPT as $key) {
            $this->assertSame($base[$key], $p[$key], $key);
        }
    }

    public function test_the_file_says_what_it_adds_and_that_it_is_knowledge_not_a_rule(): void
    {
        $source = (string) file_get_contents(self::FILE);
        $head = substr($source, 0, (int) strpos($source, '$base'));

        $this->assertMatchesRegularExpression('/knowledge about (the|this) app/i', $head);
        $this->assertMatchesRegularExpression('/not (a|the) (judging )?rule/i', $head);
        $this->assertMatchesRegularExpression('/(general|generalis|generaliz)\w*.*(designed|design) (apart|separately)|(designed|design) (apart|separately).*(general)/is', $head);
        $this->assertStringContainsString('koel_userbound', $head);
        $this->assertSame(0, preg_match('~/home/|/Users/|/mnt/~', $source), 'no absolute path');
    }

    public function test_every_pointing_names_its_statement_its_reason_and_where_in_the_app_the_code_reads_it(): void
    {
        $base = array_column(self::base()['fixups'], 'name');
        $added = array_values(array_filter(self::profile()['fixups'], static fn (array $f): bool => !in_array($f['name'], $base, true)));

        $this->assertSame(
            ['users_in_the_default_organization', 'playlists_owned_by_the_login', 'playlist_songs_in_the_first_playlist', 'playlist_folders_of_the_login', 'radio_stations_of_the_login', 'podcast_subscriptions_of_the_login', 'passkeys_of_the_login', 'duplicate_uploads_of_the_login'],
            array_column($added, 'name'),
        );
        foreach ($added as $fix) {
            $this->assertNotSame('', $fix['why'], $fix['name']);
            $this->assertMatchesRegularExpression('~[A-Za-z]+\.php:\d+~', $fix['evidence'], $fix['name'] . ': the controller or repository line that reads the column');
            $this->assertMatchesRegularExpression('/^(UPDATE|INSERT INTO) `?[a-z_]+`? /', $fix['sql'], $fix['name']);
            $this->assertStringNotContainsString(';', $fix['sql'], $fix['name'] . ': one statement');
            $this->assertSame(0, preg_match('/\b(DROP|ALTER|TRUNCATE|CREATE|RENAME|GRANT|LOCK|COMMIT|BEGIN|DELETE)\b/i', $fix['sql']), $fix['name'] . ': nothing that commits or removes');
        }
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
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, organization_id TEXT, name TEXT, email TEXT, password TEXT, preferences TEXT, subsonic_api_key TEXT, subsonic_api_key_hash TEXT)');
        $pdo->exec('CREATE TABLE songs (id TEXT PRIMARY KEY, podcast_id TEXT, episode_guid TEXT, title TEXT)');
        $pdo->exec('CREATE TABLE playlists (id TEXT PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE playlist_user (playlist_id TEXT, user_id INTEGER, role TEXT, position INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE playlist_song (id INTEGER PRIMARY KEY AUTOINCREMENT, playlist_id TEXT, song_id TEXT, user_id INTEGER)');
        $pdo->exec('CREATE TABLE playlist_folders (id TEXT PRIMARY KEY, name TEXT, user_id INTEGER)');
        $pdo->exec('CREATE TABLE radio_stations (id TEXT PRIMARY KEY, name TEXT, user_id INTEGER)');
        $pdo->exec('CREATE TABLE podcast_user (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, podcast_id TEXT)');
        $pdo->exec('CREATE TABLE passkeys (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT)');
        $pdo->exec('CREATE TABLE duplicate_uploads (id TEXT PRIMARY KEY, user_id INTEGER, location TEXT)');
        $pdo->exec('CREATE TABLE artists (id TEXT PRIMARY KEY, name TEXT, user_id INTEGER)');
        $pdo->exec('CREATE TABLE albums (id TEXT PRIMARY KEY, name TEXT, artist_id TEXT, user_id INTEGER, created_at TEXT, updated_at TEXT)');

        // what the generator leaves behind: six users in a generated organization, rows that go round the users
        $pdo->exec("INSERT INTO organizations (id, name, slug) VALUES ('generated-org', 'organizations_name_1', 'organizations_slug_1')");
        foreach ([1, 2, 3, 4, 5, 6] as $id) {
            $pdo->exec("INSERT INTO users (id, organization_id, name, email, password, preferences) VALUES ($id, 'generated-org', 'users_name_$id', 'users_email_$id', 'x', '{}')");
            $pdo->exec("INSERT INTO songs (id, podcast_id, episode_guid, title) VALUES ('s$id', 'p$id', 'g$id', 't$id')");
            $pdo->exec("INSERT INTO playlists (id, name) VALUES ('pl$id', 'playlists_name_$id')");
            $pdo->exec("INSERT INTO playlist_user (playlist_id, user_id, role) VALUES ('pl$id', $id, 'owner')");
            $pdo->exec("INSERT INTO playlist_song (playlist_id, song_id, user_id) VALUES ('pl$id', 's$id', $id)");
            $pdo->exec("INSERT INTO playlist_folders (id, name, user_id) VALUES ('f$id', 'folders_name_$id', $id)");
            $pdo->exec("INSERT INTO radio_stations (id, name, user_id) VALUES ('r$id', 'stations_name_$id', $id)");
            $pdo->exec("INSERT INTO podcast_user (user_id, podcast_id) VALUES ($id, 'pc$id')");
            $pdo->exec("INSERT INTO passkeys (user_id, name) VALUES ($id, 'passkeys_name_$id')");
            $pdo->exec("INSERT INTO duplicate_uploads (id, user_id, location) VALUES ('d$id', $id, 'loc_$id')");
            $pdo->exec("INSERT INTO artists (id, name, user_id) VALUES ('a$id', 'artists_name_$id', $id)");
        }
        // fewer albums than artists, as in a run where only the artists table grows
        $pdo->exec("INSERT INTO albums (id, name, artist_id, user_id) VALUES ('01JAAAAAAAAAAAAAAAAAAAAAA1', 'albums_name_1', 'a1', 1)");
        $pdo->exec("INSERT INTO albums (id, name, artist_id, user_id) VALUES ('01JAAAAAAAAAAAAAAAAAAAAAA2', 'albums_name_2', 'a2', 2)");

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

    public function test_it_still_does_everything_the_first_profile_does(): void
    {
        $pdo = self::standIn();

        $summary = self::apply($pdo);

        $this->assertSame(1, (int) self::scalar($pdo, "SELECT COUNT(*) FROM organizations WHERE slug = 'koel'"));
        $this->assertSame(2, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM model_has_roles'));
        $this->assertSame(0, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM songs WHERE podcast_id IS NOT NULL'));
        $this->assertSame(6, $summary['fixups']['songs_are_not_episodes']);
    }

    public function test_every_user_is_in_the_default_organization_so_the_organization_list_grows_with_them(): void
    {
        $pdo = self::standIn();

        $summary = self::apply($pdo);

        $org = self::scalar($pdo, "SELECT id FROM organizations WHERE slug = 'koel'");
        $this->assertSame(6, (int) self::scalar($pdo, "SELECT COUNT(*) FROM users WHERE organization_id = '$org'"));
        $this->assertIsInt($summary['fixups']['users_in_the_default_organization'], 'the count of rows it changed is reported');
    }

    public function test_the_login_owns_every_playlist_once(): void
    {
        $pdo = self::standIn();

        $summary = self::apply($pdo);

        $this->assertSame(6, (int) self::scalar($pdo, "SELECT COUNT(*) FROM playlist_user WHERE user_id = 1 AND role = 'owner'"));
        $this->assertSame(6, (int) self::scalar($pdo, 'SELECT COUNT(DISTINCT playlist_id) FROM playlist_user WHERE user_id = 1'));
        $this->assertSame(5, $summary['fixups']['playlists_owned_by_the_login'], 'the one it owned already is not added again');
        $this->assertSame(6 + 5, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM playlist_user'), 'the other owners stay');
    }

    public function test_the_songs_of_a_playlist_all_sit_in_the_playlist_the_tool_asks_for_first(): void
    {
        $pdo = self::standIn();

        self::apply($pdo);

        $this->assertSame(1, (int) self::scalar($pdo, 'SELECT COUNT(DISTINCT playlist_id) FROM playlist_song'));
        $this->assertSame('pl1', self::scalar($pdo, 'SELECT playlist_id FROM playlist_song LIMIT 1'), 'the first row by key');
        $this->assertSame(6, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM playlist_song'));
    }

    public function test_folders_stations_subscriptions_passkeys_and_duplicate_uploads_all_belong_to_the_login(): void
    {
        $pdo = self::standIn();

        self::apply($pdo);

        foreach (['playlist_folders', 'radio_stations', 'podcast_user', 'passkeys', 'duplicate_uploads'] as $table) {
            $this->assertSame(6, (int) self::scalar($pdo, "SELECT COUNT(*) FROM $table WHERE user_id = 1"), $table);
        }
    }

    public function test_an_artist_with_no_album_gets_one_and_an_artist_with_one_does_not(): void
    {
        $pdo = self::standIn();

        $summary = self::apply($pdo);

        $this->assertSame(6, (int) self::scalar($pdo, 'SELECT COUNT(DISTINCT artist_id) FROM albums'));
        $this->assertSame(6, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM albums'), 'two existed; four are new');
        $this->assertSame(4, $summary['fixups']['albums_for_artists_without_one']);
        foreach ($pdo->query("SELECT id, name, user_id FROM albums WHERE id LIKE '01K%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $row['id'], 'a ULID the app accepts as a key');
            $this->assertSame(1, (int) $row['user_id']);
            $this->assertNotSame('', $row['name']);
        }
    }

    public function test_a_second_run_changes_nothing_more(): void
    {
        $pdo = self::standIn();
        self::apply($pdo);
        $counts = static fn (): array => array_map(
            static fn (string $t): int => (int) self::scalar($pdo, "SELECT COUNT(*) FROM $t"),
            ['users', 'playlist_user', 'playlist_song', 'albums', 'roles', 'organizations'],
        );
        $before = $counts();

        $again = self::apply($pdo);

        $this->assertSame($before, $counts());
        $this->assertSame(0, $again['fixups']['playlists_owned_by_the_login']);
        $this->assertSame(0, $again['fixups']['albums_for_artists_without_one']);
    }

    public function test_it_refuses_a_connection_that_is_not_a_measurement_database(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/measurement database/i');
        (self::profile()['after_generation'])(self::standIn(), 'app');
    }
}
