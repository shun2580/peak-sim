<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\DockerApps\Koel;

use PDO;
use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Data\Generator;
use PeakSim\Runtime\Data\ScalePlan;
use PeakSim\Runtime\Tests\Docker\Data\Support\GenerationCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Koel profile against the real app: the migrations of Koel are run into a measurement database, the data
 * generator fills it with the profile, the profile's `after_generation` adds what it declares, and the app is then asked
 * for pages as the two logins.
 *
 * This is NOT part of the RuntimeDocker suite (that suite runs against whatever app is mounted, the verification app by
 * default). It needs Koel mounted at /app, so name the file when you run it:
 *
 *   PEAK_APP_ROOT=<a Koel checkout with vendor/> bin/stack up      (a PHP image with the extensions Koel needs)
 *   bin/stack exec /peak-sim/vendor/bin/phpunit -c /peak-sim/poc/runtime/phpunit.runtime.xml \
 *       /peak-sim/poc/runtime/tests/DockerApps/Koel/KoelProfileDockerTest.php
 *
 * Started against another app it fails and says so; it never skips.
 */
#[Group('in-stack')]
final class KoelProfileDockerTest extends GenerationCase
{
    private const SCALE = 100;

    private const SUPPORT = '/peak-sim/poc/runtime/tests/DockerApps/Koel/Support';

    /** @var array<string, mixed> */
    private static array $profile;

    /** @var array<string, mixed> */
    private static array $report;

    /** @var array<string, mixed> */
    private static array $applied;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::assertFileExists(
            self::config()->appRoot . '/app/Models/Organization.php',
            'This test needs Koel mounted at /app (PEAK_APP_ROOT=<a Koel checkout> bin/stack up); it does not run against another app.',
        );

        // The run's one APP_KEY, as bin/measure makes it: the profile reads it to store the logins' Subsonic API keys
        // encrypted, and every child process below inherits it, so the app decrypts what the profile stored.
        putenv(AppBooter::APP_KEY_ENV . '=' . AppBooter::newAppKey());
        self::fresh(self::SCALE);
        self::$profile = (static fn (): array => require '/peak-sim/poc/runtime/profiles/koel.php')();
        $pdo = self::pdo(self::SCALE);
        self::$report = (new Generator())->generate($pdo, ScalePlan::global(self::SCALE, 5), self::$profile)->toArray();
        self::$applied = (self::$profile['after_generation'])($pdo, self::config()->dbNameForScale(self::SCALE));
    }

    public static function tearDownAfterClass(): void
    {
        putenv(AppBooter::APP_KEY_ENV);
        self::drop(self::SCALE);
    }

    /** @return array{status: int, items: ?int, bytes: int} */
    private static function request(string $userKey, string $uri): array
    {
        [$exit, $out, $err] = self::php(self::SUPPORT . '/request.php', [(string) self::SCALE, $userKey, $uri]);
        self::assertSame(0, $exit, 'request.php failed: ' . mb_substr($err, -500));
        $decoded = json_decode(trim(strstr($out, '{') ?: ''), true);
        self::assertIsArray($decoded, 'request.php printed no result: ' . mb_substr($out, -300));

        return $decoded;
    }

    public function testTheGeneratorFilledEveryTableThatTheProfileDidNotSkip(): void
    {
        $this->assertSame(0, self::$report['failed'], json_encode(self::$report['reasons']));
        $skipped = array_column(array_filter(self::$report['tables'], static fn (array $t): bool => $t['reason'] === 'profile_skip'), 'table');
        sort($skipped);
        $declared = self::$profile['skip'];
        sort($declared);
        $this->assertSame($declared, $skipped, 'the tables left empty are exactly the ones the profile names');
    }

    public function testTheReferenceRowsAreThereOnceAndTheSkippedTablesHoldNothingElse(): void
    {
        $pdo = self::pdo(self::SCALE);

        // the default one the migrations make, and the one the generator needed as a parent while it wrote the users
        $this->assertSame(2, self::rowCount($pdo, 'organizations'));
        $this->assertSame(1, (int) self::scalar($pdo, "SELECT COUNT(*) FROM organizations WHERE slug = 'koel'"));
        $this->assertSame(2, (int) self::scalar($pdo, "SELECT COUNT(*) FROM users u JOIN organizations o ON o.id = u.organization_id WHERE o.slug = 'koel'"), 'only the two logins live in the default organization');
        $this->assertSame(['admin', 'guest', 'manager', 'user'], $pdo->query('SELECT name FROM roles ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(5, self::rowCount($pdo, 'permissions'));
        $this->assertSame(9, self::rowCount($pdo, 'role_has_permissions'), 'admin 5 + manager 4');
        $this->assertSame(1, (int) self::scalar($pdo, "SELECT COUNT(*) FROM settings WHERE `key` = 'media_path' AND organization_id IS NULL"));
        $this->assertSame(0, self::rowCount($pdo, 'licenses'), 'no licence row: the community edition');
        $this->assertSame(2, self::rowCount($pdo, 'model_has_roles'), 'only the two logins hold a role');
        $this->assertSame(0, self::rowCount($pdo, 'model_has_permissions'));

        // the application of the profile is repeatable: a second run adds nothing
        $again = (self::$profile['after_generation'])($pdo, self::config()->dbNameForScale(self::SCALE));
        $this->assertSame(0, $again['reference_rows']['inserted']);
        $this->assertSame(0, $again['role_permissions']);
        $this->assertSame(2, self::rowCount($pdo, 'model_has_roles'));
    }

    public function testEveryTableAndColumnTheProfileNamesIsInKoelsSchema(): void
    {
        $pdo = self::pdo(self::SCALE);
        $columns = [];
        foreach ($pdo->query('SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[$row['t']][$row['c']] = true;
        }
        $p = self::$profile;

        foreach ($p['skip'] as $table) {
            $this->assertArrayHasKey($table, $columns, "skip: $table");
        }
        foreach ($p['rows'] as $table => $n) {
            $this->assertArrayHasKey($table, $columns, "rows: $table");
        }
        foreach ($p['values'] as $table => $values) {
            foreach (array_keys($values) as $column) {
                $this->assertArrayHasKey($column, $columns[$table] ?? [], "values: $table.$column");
            }
        }
        foreach ($p['reference_rows'] as $entry) {
            foreach (array_keys($entry['row']) as $column) {
                $this->assertArrayHasKey($column, $columns[$entry['table']] ?? [], "reference_rows: {$entry['table']}.$column");
            }
        }
        foreach ($p['fixups'] as $fix) {
            preg_match('/^UPDATE `?([a-z_]+)`? SET (.+)$/', $fix['sql'], $m);
            preg_match_all('/`?([a-z_]+)`? = /', $m[2], $set);
            foreach ($set[1] as $column) {
                $this->assertArrayHasKey($column, $columns[$m[1]] ?? [], "fixup {$fix['name']}: {$m[1]}.$column");
            }
        }
        foreach ($p['arguments'] as $param => $how) {
            $this->assertArrayHasKey($how['column'], $columns[$how['table']] ?? [], "arguments: $param");
            $this->assertGreaterThan(0, self::rowCount($pdo, $how['table']), "arguments: $param needs a row in {$how['table']}");
            $this->assertNotNull(self::scalar($pdo, sprintf('SELECT `%s` FROM `%s` ORDER BY 1 LIMIT 1', $how['column'], $how['table'])), "arguments: $param has a value");
        }
    }

    public function testTheFixupsChangedRowsAndTheLoginsAreInTheDefaultOrganization(): void
    {
        $pdo = self::pdo(self::SCALE);

        $this->assertSame(self::SCALE, self::$applied['fixups']['songs_are_not_episodes']);
        $this->assertSame(0, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM songs WHERE podcast_id IS NOT NULL'));
        $org = self::scalar($pdo, "SELECT id FROM organizations WHERE slug = 'koel'");
        $this->assertSame(2, (int) self::scalar($pdo, "SELECT COUNT(*) FROM users WHERE organization_id = '$org' AND id IN (1, 2)"));
        $this->assertSame(
            ['admin' => 2, 'user' => 1],
            array_map('intval', $pdo->query("SELECT r.name, m.model_id FROM model_has_roles m JOIN roles r ON r.id = m.role_id ORDER BY r.name")->fetchAll(PDO::FETCH_KEY_PAIR)),
        );
    }

    public function testBothLoginsGetTheSongListAndAVisitorDoesNot(): void
    {
        foreach (['1' => 'user', '2' => 'admin'] as $key => $who) {
            $result = self::request((string) $key, '/api/songs');
            $this->assertSame(200, $result['status'], $who);
            $this->assertGreaterThan(0, $result['items'], "$who sees songs (a song with a podcast would be hidden)");
        }
        $this->assertSame(401, self::request('-', '/api/songs')['status']);
    }

    public function testAnAdminOnlyRouteIsForbiddenToTheUserAndOpenToTheAdmin(): void
    {
        $this->assertSame(403, self::request('1', '/api/users')['status']);
        $this->assertSame(200, self::request('2', '/api/users')['status']);
    }

    public function testTheFirstPagesOfTheMainScreensLoadForTheUser(): void
    {
        $statuses = [];
        foreach (['/api/data', '/api/me', '/api/albums', '/api/artists', '/api/playlists', '/api/podcasts', '/api/radio/stations'] as $uri) {
            $statuses[$uri] = self::request('1', $uri)['status'];
        }

        $this->assertSame(array_fill_keys(array_keys($statuses), 200), $statuses);
    }

    public function testEveryRouteArgumentTheToolCannotFillIsDeclaredOrExplainedByTheProfile(): void
    {
        [$exit, $out, $err] = self::php(self::SUPPORT . '/arguments.php', [(string) self::SCALE]);
        $this->assertSame(0, $exit, mb_substr($err, -500));
        $result = json_decode(trim(strstr($out, '{') ?: ''), true);
        $this->assertIsArray($result);

        $declared = array_keys(self::$profile['arguments']);
        $unfillable = [];
        foreach (self::$profile['unfillable'] as $entry) {
            foreach ($entry['params'] ?? [] as $param) {
                $unfillable[$param] = $entry['reason'];
            }
        }

        $summary = ['routes_with_arguments' => count($result['routes']), 'not_defined' => 0, 'tool_alone' => 0, 'with_profile_arguments' => 0, 'unfillable' => [], 'uncovered' => []];
        foreach ($result['routes'] as $route) {
            if ($route['action_not_defined']) {
                ++$summary['not_defined'];
                continue; // the route is recorded as action_not_defined and never hit: its arguments do not matter
            }
            if ($route['unfilled'] === []) {
                ++$summary['tool_alone'];
                continue;
            }
            $reasons = [];
            foreach ($route['unfilled'] as $u) {
                $reasons[] = in_array($u['param'], $declared, true) ? null : ($unfillable[$u['param']] ?? 'UNEXPLAINED:' . $u['param']);
            }
            $reasons = array_values(array_filter($reasons));
            if ($reasons === []) {
                ++$summary['with_profile_arguments'];
            }
            foreach ($reasons as $reason) {
                if (str_starts_with($reason, 'UNEXPLAINED:')) {
                    $summary['uncovered'][] = $route['method'] . ' ' . $route['uri'] . ' ' . substr($reason, 12);
                } else {
                    $summary['unfillable'][$reason] = ($summary['unfillable'][$reason] ?? 0) + 1;
                }
            }
        }

        self::writeOut('koel-profile-arguments.json', $summary + ['laravel' => $result['laravel']]);
        $this->assertSame([], $summary['uncovered'], 'a parameter the tool cannot fill and the profile neither declares nor explains');
        $this->assertGreaterThan(0, $summary['with_profile_arguments']);
    }

    public function testNoArgumentIsDeclaredThatOnlyRoutesToUndefinedOperationsNeed(): void
    {
        [$exit, $out, $err] = self::php(self::SUPPORT . '/arguments.php', [(string) self::SCALE]);
        $this->assertSame(0, $exit, mb_substr($err, -500));
        $result = json_decode(trim(strstr($out, '{') ?: ''), true);
        $this->assertIsArray($result);

        // What the tool alone cannot fill, split by whether the route's operation exists in the controller.
        $needed = [];
        $missingOnly = [];
        $notDefined = 0;
        foreach ($result['routes'] as $route) {
            $notDefined += $route['action_not_defined'] ? 1 : 0;
            foreach ($route['unfilled'] as $u) {
                if ($route['action_not_defined']) {
                    $missingOnly[$u['param']] = true;
                } else {
                    $needed[$u['param']] = true;
                }
            }
        }
        $onlyMissing = array_values(array_diff(array_keys($missingOnly), array_keys($needed)));
        sort($onlyMissing);

        $this->assertGreaterThan(0, $notDefined, 'Koel has routes to operations its controllers do not define');
        $this->assertSame(['album', 'artist', 'playlist', 'song', 'station'], $onlyMissing, 'the arguments only those routes leave unfilled (measured on the real app)');
        $declared = array_keys(self::$profile['arguments']);
        $unfillable = array_merge(...array_map(static fn (array $e): array => $e['params'] ?? [], array_values(self::$profile['unfillable'])));
        foreach ($onlyMissing as $param) {
            $this->assertNotContains($param, $declared, "$param is declared though only a route that is never hit needs it");
            $this->assertNotContains($param, $unfillable, "$param is explained as unfillable though only a route that is never hit needs it");
        }
        $this->assertContains('playlist_folder', $declared, 'routes that exist still need playlist_folder');
    }

    public function testTheAppAcceptsTheSubsonicApiKeysTheProfileStoredAndRefusesAWrongOne(): void
    {
        $this->assertSame(2, self::$applied['subsonic']['api_keys']);

        [$exit, $out, $err] = self::php(self::SUPPORT . '/subsonic.php', [(string) self::SCALE, '1']);
        $this->assertSame(0, $exit, 'subsonic.php failed: ' . mb_substr($err, -500));
        $r = json_decode(trim(strstr($out, '{') ?: ''), true);
        $this->assertIsArray($r, mb_substr($out, -300));

        $this->assertTrue($r['model_reads_key'], 'the app\'s own encrypted cast decrypts what the profile stored, under the run\'s shared key');
        $this->assertSame(['http' => 200, 'status' => 'ok'], $r['by_api_key'], 'ping by API key');
        $this->assertSame(['http' => 200, 'status' => 'ok'], $r['by_token'], 'ping by token and salt (the app decrypts the stored key to check the token)');
        $this->assertNotSame('ok', $r['by_wrong_api_key']['status']);
        $this->assertNotSame('ok', $r['without_credentials']['status'], 'a request that carries no credentials is not authenticated: the tool\'s requests must carry them');
    }
}

