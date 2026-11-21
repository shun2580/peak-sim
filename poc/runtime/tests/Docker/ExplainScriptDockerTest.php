<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Route\RouteSpec;

/**
 * `bin/explain-at-scale`, the host-side entry point, against real Docker, on the verification app: it builds the databases of a
 * recorded task list, makes the statistics states, asks for the plan of every statement type of a capture, writes explain.json,
 * and the plans of a DELETE and an UPDATE change no row.
 *
 * run.sh mounts the repository and the run directory at their host paths too, so the paths given to the script are valid both for
 * this process and for the Docker daemon. The capture is made by hand here (the form of CAPTURE_FORMAT.md): what `bin/capture`
 * would write for these routes is not the subject of this test.
 */
final class ExplainScriptDockerTest extends StackTestCase
{
    private const SCALE = 3000;

    private const SMALL = 300;

    private static string $repo = '';

    private static string $work = '';

    public static function setUpBeforeClass(): void
    {
        self::requireDocker();
        static::$stackEnv = [];
        self::$repo = (string) getenv('PEAK_REPO_ROOT');
        self::$work = rtrim((string) getenv('PEAK_OUT_DIR'), '/') . '/explain-script-' . self::randomHex(3);
        mkdir(self::$work, 0775, true);
        file_put_contents(self::$work . '/tasks.json', json_encode([self::task('/ok/eager', 'GET'), self::task('/ng/n-plus-one', 'GET')], JSON_THROW_ON_ERROR));
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownStack();
        if (!str_contains(basename(self::$work), 'explain-script-') || !is_dir(self::$work)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$work, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            ($item->isDir() && !$item->isLink()) ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir(self::$work);
    }

    /** @return array<string, mixed> */
    private static function task(string $uri, string $method): array
    {
        $spec = new RouteSpec($uri, $method, [$method], [], [], 'A@b', [
            'query' => ['growth' => 'O(N)', 'lower_bound' => null, 'confidence' => 'high'],
            'memory' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'n_table' => 'orders', 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => [],
        ], true);

        return (new RouteTask($spec, $uri, null, null, null, [], false, true))->toArray();
    }

    /**
     * @param list<array{0: string, 1: string, 2: list<mixed>}> $types sql, kind, bindings
     */
    private static function capture(array $types, string $route = 'GET /ok/eager'): string
    {
        $statementTypes = array_map(static fn (array $t): array => [
            'id' => 's' . substr(sha1($t[0]), 0, 10), 'kind' => $t[1], 'pattern' => $t[0],
            'representative' => ['scale' => 10000, 'at_representative_scale' => true, 'sql' => $t[0], 'bindings' => $t[2]],
        ], $types);
        $file = self::$work . '/capture-' . self::randomHex(3) . '.json';
        file_put_contents($file, json_encode(['capture_version' => 1, 'app' => 'laravel-app', 'profile' => 'fixtures', 'data' => 'generator', 'scales' => [100, 1000, 10000], 'representative_scale' => 10000, 'routes' => [
            ['route' => $route, 'method' => 'GET', 'uri' => '/ok/eager', 'status' => 'captured', 'reason' => null, 'captured_scales' => [100, 1000, 10000], 'totals' => [], 'statement_types' => $statementTypes],
        ]], JSON_THROW_ON_ERROR));

        return $file;
    }

    /** @return array<string, string> */
    private static function stack(string $suffix): array
    {
        return [
            'PEAK_COMPOSE_PROJECT' => self::devProject(((string) (getenv('PEAK_COMPOSE_PROJECT') ?: self::DEV_PROJECT_PREFIX)) . $suffix),
            'PEAK_COMPOSE_FILE' => self::runtimeDir() . '/compose/measure.compose.yaml',
            'PEAK_MEASURE_DB_ROOT_PASSWORD' => self::randomHex(12),
            'PEAK_MEASURE_DB_PASSWORD' => self::randomHex(12),
        ];
    }

    /**
     * @param list<string> $extra
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function explainRun(string $label, string $capture, array $extra = []): array
    {
        return self::bin('explain-at-scale', [
            '--capture=' . $capture, '--tasks=' . self::$work . '/tasks.json', '--app=' . self::$repo . '/fixtures/laravel-app',
            '--scale=' . self::SCALE, '--small-scale=' . self::SMALL, '--out=' . self::$work . '/out', '--label=' . $label, ...$extra,
        ], ['PEAK_OUT_DIR' => self::$work . '/out/' . $label]);
    }

    protected function tearDown(): void
    {
        self::tearDownStack(); // the runs keep their stack up (--keep-stack) so the test can look at the databases; it is removed here
        static::$project = '';
    }

    public function testItExplainsTheStatementsOfTheCaptureAndNoRowChanges(): void
    {
        static::$stackEnv = self::stack('-eas');
        static::$project = static::$stackEnv['PEAK_COMPOSE_PROJECT'];
        static::$composeFile = 'measure.compose.yaml';
        $capture = self::capture([
            ['select * from `orders` where `status` = ? order by `id` desc limit 20', 'select', ['paid']],
            ['select * from `items` where `order_id` in (?, ?, ?)', 'select', [1, 2, 3]],
            ['delete from `items` where `order_id` = ?', 'delete', [5]],
            ['update `orders` set `status` = ? where `id` = ?', 'update', ['zz-sentinel-not-written', 7]],
            ['select count(*) as aggregate from `orders` where `total` > ?', 'select', ['10.50']],
            ['begin', 'begin', []],
            ['select * from `no_such_table` where `id` = ?', 'select', [1]],
        ]);

        $result = self::explainRun('ok', $capture, ['--keep-db', '--keep-stack']);
        self::evidence('explain', 'bin-explain-at-scale-run.txt', self::transcript('bin/explain-at-scale --scale=3000 --small-scale=300 (fixtures app, 1 route)', [$result[0], substr($result[1], -3500), substr($result[2], -1500)]));

        $this->assertSame(0, $result[0], $result[1] . $result[2]);
        $run = self::$work . '/out/ok';
        $report = json_decode((string) file_get_contents($run . '/explain.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['explain_version']);
        $this->assertSame(['default' => 20, 'low' => 4, 'high' => 200], $report['states']);
        $this->assertSame(7, $report['totals']['statement_types']);
        $this->assertSame(2, $report['totals']['explain_unavailable'], 'the BEGIN (not an explainable kind) and the table that does not exist');

        $byPattern = [];
        foreach ($report['routes'][0]['statements'] as $s) {
            $byPattern[$s['pattern']] = $s;
        }
        $delete = $byPattern['delete from `items` where `order_id` = ?'];
        $this->assertSame(['default', 'low', 'high'], array_keys($delete['target_scale']), 'a DELETE is explained in every state');
        $this->assertArrayHasKey('raw', $delete['target_scale']['default']);
        $this->assertSame('not_explainable_kind: begin', $byPattern['begin']['marks']['explain_unavailable']);
        $this->assertStringStartsWith('server_refused: ', (string) $byPattern['select * from `no_such_table` where `id` = ?']['marks']['explain_unavailable']);

        // the statistics: made as meant, and still while the plans were taken (copies by the server's root account)
        $target = $report['statistics']['g1'];
        $this->assertSame('target', $target['role']);
        foreach (['default', 'low', 'high'] as $state) {
            $check = $target['states'][$state];
            $this->assertTrue($check['options_ok'], $state . ': options ' . json_encode($check['options_wrong_on']));
            $this->assertTrue($check['unchanged_during_explain'], $state . ': the statistics moved while the plans were taken');
            $this->assertTrue($check['ok'], $state);
        }
        $this->assertTrue($target['ordering']['ok'], json_encode($target['ordering']));
        $this->assertSame([], $target['analyze_errors']);
        foreach (glob($run . '/g1/stats_*_after_set.tsv') ?: [] as $copy) {
            $this->assertStringContainsString('innodb_table_stats', (string) file_get_contents($copy));
        }

        // nothing the capture holds was run: every table holds the rows it was built with
        $built = json_decode((string) file_get_contents($run . '/g1/build.json'), true, 512, JSON_THROW_ON_ERROR);
        [, $dbs] = self::rootSql("SHOW DATABASES LIKE 'peak\\_sim\\_measure\\_%'");
        $this->assertNotSame('', trim($dbs), 'the databases were kept (--keep-db)');
        $database = '';
        foreach (explode("\n", trim($dbs)) as $name) {
            if (str_ends_with($name, '_' . self::SCALE)) {
                $database = $name;
            }
        }
        $this->assertNotSame('', $database);
        foreach (['items', 'orders', 'customers'] as $table) {
            [$code, $count] = self::rootSql("SELECT COUNT(*) FROM `$database`.`$table`");
            $this->assertSame(0, $code);
            $this->assertSame((int) $built['rows'][$table], (int) trim($count), $table . ' still holds the rows it was built with');
        }
        [, $changed] = self::rootSql("SELECT COUNT(*) FROM `$database`.`orders` WHERE `status` = 'zz-sentinel-not-written'");
        $this->assertSame(0, (int) trim($changed), 'the UPDATE was explained, not run');
    }

    public function testItRefusesACaptureStatementThatIsNotOneExplainAndStopsWithExitTen(): void
    {
        static::$stackEnv = self::stack('-eas2');
        static::$project = static::$stackEnv['PEAK_COMPOSE_PROJECT'];
        $capture = self::capture([
            ['select * from `orders` where `id` = ?', 'select', [1]],
            ['select * from `items` where `id` = ?; delete from `items`', 'select', [1]],
        ], 'GET /ok/eager');

        $result = self::explainRun('refused', $capture, ['--keep-db', '--keep-stack']);
        self::evidence('explain', 'bin-explain-at-scale-refused.txt', self::transcript('bin/explain-at-scale (a capture with a second statement)', [$result[0], substr($result[1], -2500), substr($result[2], -1500)]));

        $this->assertSame(10, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('more than one statement', $result[1] . $result[2]);
        // the run stopped at the first database; the rows were not touched
        [, $dbs] = self::rootSql("SHOW DATABASES LIKE 'peak\\_sim\\_measure\\_%'");
        foreach (explode("\n", trim($dbs)) as $database) {
            if ($database === '') {
                continue;
            }
            [, $count] = self::rootSql("SELECT COUNT(*) FROM `$database`.`items`");
            $this->assertGreaterThan(0, (int) trim($count), 'the DELETE after the semicolon was not run');
        }
    }
}
