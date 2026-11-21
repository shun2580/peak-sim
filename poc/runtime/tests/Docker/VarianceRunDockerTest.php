<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Route\RouteSpec;

/**
 * bin/variance-run and bin/variance-watch against real Docker, on the verification app (seeded data):
 * the same database is built once and measured again and again, every trial leaves its record and the table statistics
 * before and after, ANALYZE TABLE is run once outside any request when asked for, two databases built alike hold the same
 * data (the same digest) even when they run at the same time, and the watch says so when a statement runs too long or nothing moves.
 */
final class VarianceRunDockerTest extends StackTestCase
{
    private const ROUTE = 'GET /ok/eager';

    private static string $repo = '';

    private static string $work = '';

    public static function setUpBeforeClass(): void
    {
        self::requireDocker();
        static::$stackEnv = [];
        self::$repo = (string) getenv('PEAK_REPO_ROOT');
        self::$work = rtrim((string) getenv('PEAK_OUT_DIR'), '/') . '/variance-run-' . self::randomHex(3);
        mkdir(self::$work, 0775, true);
        $spec = new RouteSpec('/ok/eager', 'GET', ['GET'], [], [], null, [
            'query' => ['growth' => 'O(1)', 'lower_bound' => null, 'confidence' => 'high'],
            'memory' => ['growth' => 'O(N)', 'lower_bound' => null, 'confidence' => null],
            'n_table' => 'orders', 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => [],
        ], true);
        file_put_contents(self::$work . '/tasks.json', json_encode([(new RouteTask($spec, '/ok/eager'))->toArray()], JSON_THROW_ON_ERROR));
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownStack();
        if (!str_contains(basename(self::$work), 'variance-run-') || !is_dir(self::$work)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$work, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            ($item->isDir() && !$item->isLink()) ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir(self::$work);
    }

    /**
     * @param list<string> $options
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function varianceRun(string $label, array $options): array
    {
        $result = self::bin('variance-run', [
            '--app=' . self::$repo . '/fixtures/laravel-app', '--tasks=' . self::$work . '/tasks.json', '--scale=20', '--out=' . self::$work,
            '--label=' . $label, '--source-commit=test', '--data=seeder', '--profile=none', '--tap=off', '--routes=' . self::ROUTE, '--watch-interval=2',
            '--project-prefix=' . self::devProject(((string) getenv('PEAK_COMPOSE_PROJECT') ?: self::DEV_PROJECT_PREFIX) . '-var'), '--purge', ...$options,
        ]);
        self::evidence('variance', $label . '.txt', self::transcript('bin/variance-run ' . implode(' ', $options), [$result[0], substr($result[1], -2000), substr($result[2], -2000)]) . self::tail(self::$work . '/' . $label . '/db1/run.log'));

        return $result;
    }

    private static function tail(string $file): string
    {
        return is_file($file) ? "[run.log]\n" . substr((string) file_get_contents($file), -3000) : '';
    }

    /** @return array<string, mixed> */
    private static function trial(string $dir): array
    {
        $data = json_decode((string) file_get_contents($dir . '/trial.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function assertNoStackLeft(string $label): void
    {
        [, $left] = self::exec(['docker', 'ps', '-aq', '--filter', 'name=-var-' . $label . '-db']);
        $this->assertSame('', trim($left), 'the stacks are torn down after the run');
    }

    public function testItBuildsOnceAndMeasuresTheSameDatabaseAgainWithItsRecordAndStatistics(): void
    {
        $result = self::varianceRun('once', ['--dbs=1', '--laps=2']);
        $this->assertSame(0, $result[0], $result[1] . $result[2] . self::tail(self::$work . '/once/db1/run.log'));

        $db = self::$work . '/once/db1';
        $this->assertCount(1, glob($db . '/g1/build.json') ?: [], 'one build');
        $this->assertSame(1, preg_match_all('/: building /', (string) file_get_contents($db . '/run.log')), 'the database is built once');
        $trials = array_map(static fn (string $dir): array => self::trial($dir), [...(glob($db . '/g1/plain_lap1/*') ?: []), ...(glob($db . '/g1/plain_lap2/*') ?: [])]);
        $this->assertCount(2, $trials);
        $this->assertSame($trials[0]['database'], $trials[1]['database'], 'the same database');
        $this->assertSame([1, 2], [$trials[0]['order'], $trials[1]['order']]);
        $this->assertSame([1, 2], [$trials[0]['lap'], $trials[1]['lap']]);
        foreach ($trials as $trial) {
            $this->assertSame(0, $trial['exit']);
            $this->assertSame(2, $trial['attempts'], 'a warm-up and a cold request');
            $this->assertSame(2, $trial['attempts_ok']);
            $this->assertSame(self::ROUTE, $trial['route']);
        }
        foreach (array_merge(glob($db . '/g1/plain_lap*/*/stats_before.tsv') ?: [], glob($db . '/g1/plain_lap*/*/stats_after.tsv') ?: []) as $file) {
            $stats = (string) file_get_contents($file);
            $this->assertStringContainsString("innodb_table_stats\torders\t", $stats, $file);
            $this->assertStringContainsString("cardinality\torders\t", $stats, $file);
        }
        $this->assertCount(2, file($db . '/index.tsv') ?: []);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', trim((string) file_get_contents($db . '/g1/content.sha256')));
        $this->assertStringContainsString("rows\torders\t20\n", (string) file_get_contents($db . '/g1/content.tsv'));
        $this->assertStringContainsString("checksum\torders\t", (string) file_get_contents($db . '/g1/content.tsv'));
        $build = json_decode((string) file_get_contents($db . '/g1/build.json'), true);
        $this->assertSame(20, $build['scale'] ?? null);
        foreach (['stats_off_tables.txt', 'stats_off.log', 'stats_after_off.tsv', 'table_options_before_off.tsv', 'table_options_after_off.tsv'] as $name) {
            $this->assertFileDoesNotExist($db . '/g1/' . $name, 'the default (keep) leaves no trace of the statistics switch: ' . $name);
        }
        $this->assertDirectoryDoesNotExist($db . '/g1/statsoff_lap1');
        $this->assertStringNotContainsString('stats_auto_recalc', (string) file_get_contents(self::$work . '/once/run.txt'), 'the record of a default run is the one it always was');
        $this->assertNoStackLeft('once');
    }

    public function testItSwitchesTheAutomaticStatisticsRecalculationOffAfterOneAnalyzeAndTheStatisticsStayPutDuringTheTrials(): void
    {
        $result = self::varianceRun('statsoff', ['--dbs=1', '--laps=2', '--stats-auto-recalc=off', '--stats-tables=orders,no_such_table']);
        $this->assertSame(0, $result[0], $result[1] . $result[2] . self::tail(self::$work . '/statsoff/db1/run.log'));

        $db = self::$work . '/statsoff/db1/g1';
        $this->assertStringContainsString('stats_auto_recalc=off stats_tables=orders,no_such_table', (string) file_get_contents(self::$work . '/statsoff/run.txt'));
        $this->assertSame("orders\n", (string) file_get_contents($db . '/stats_off_tables.txt'), 'only the tables that exist are switched, and they are written down');
        $this->assertSame(1, preg_match_all('/: ANALYZE TABLE /', (string) file_get_contents(self::$work . '/statsoff/db1/run.log')), 'ANALYZE TABLE once');
        $this->assertStringContainsString('OK', (string) file_get_contents($db . '/analyze.log'));
        $this->assertStringNotContainsString('stats_auto_recalc=0', strtolower((string) file_get_contents($db . '/table_options_before_off.tsv')), 'the option is set after the ANALYZE, not before');
        $this->assertMatchesRegularExpression('/^orders\t.*stats_auto_recalc=0/mi', (string) file_get_contents($db . '/table_options_after_off.tsv'));
        $this->assertFileExists($db . '/stats_after_off.tsv');
        $this->assertDirectoryDoesNotExist($db . '/plain_lap1', 'the trials are of the statsoff phase');
        $trials = [...(glob($db . '/statsoff_lap1/*') ?: []), ...(glob($db . '/statsoff_lap2/*') ?: [])];
        $this->assertCount(2, $trials);
        foreach ($trials as $dir) {
            $this->assertSame(0, self::trial($dir)['exit']);
            $this->assertSame(
                self::statsOf($db . '/stats_after_off.tsv', 'orders'),
                self::statsOf($dir . '/stats_after.tsv', 'orders'),
                'the statistics of orders did not move through the trial: ' . $dir,
            );
            $this->assertSame(self::statsOf($dir . '/stats_before.tsv', 'orders'), self::statsOf($dir . '/stats_after.tsv', 'orders'));
        }
        $this->assertNoStackLeft('statsoff');
    }

    private static function statsOf(string $file, string $table): string
    {
        $lines = array_filter(file($file, FILE_IGNORE_NEW_LINES) ?: [], static fn (string $l): bool => str_starts_with($l, "innodb_table_stats\t" . $table . "\t"));

        return implode("\n", $lines);
    }

    public function testItRunsAnalyzeTableOnceBetweenTheLapsWhenAskedAndLapsRunBeforeAndAfter(): void
    {
        $result = self::varianceRun('analyze', ['--dbs=1', '--laps=1', '--analyze=rerun']);
        $this->assertSame(0, $result[0], $result[1] . $result[2] . self::tail(self::$work . '/analyze/db1/run.log'));

        $db = self::$work . '/analyze/db1/g1';
        $this->assertStringContainsString('orders', (string) file_get_contents($db . '/analyze.log'));
        $this->assertStringContainsString('OK', (string) file_get_contents($db . '/analyze.log'));
        $this->assertFileExists($db . '/stats_before_analyze.tsv');
        $this->assertFileExists($db . '/stats_after_analyze.tsv');
        $before = self::trial((string) (glob($db . '/plain_lap1/*') ?: [''])[0]);
        $after = self::trial((string) (glob($db . '/analyzed_lap1/*') ?: [''])[0]);
        $this->assertSame($before['database'], $after['database'], 'the same database is measured after ANALYZE TABLE');
        $this->assertSame([1, 2], [$before['order'], $after['order']]);
        $this->assertSame(1, preg_match_all('/: ANALYZE TABLE /', (string) file_get_contents(self::$work . '/analyze/db1/run.log')), 'ANALYZE TABLE once');
        $this->assertNoStackLeft('analyze');
    }

    public function testTwoDatabasesAtTheSameTimeHoldTheSameDataAndTheWatchEndsOnItsOwn(): void
    {
        // The image the two stacks share must exist before they start at once.
        $built = self::exec(
            ['docker', 'compose', '-p', 'peak-var-image', '-f', self::runtimeDir() . '/compose/measure.compose.yaml', 'build', 'measure-php'],
            ['PEAK_REPO_ROOT' => self::$repo, 'PEAK_OUT_DIR' => self::$work, 'PEAK_MEASURE_DB_ROOT_PASSWORD' => 'x', 'PEAK_MEASURE_DB_PASSWORD' => 'x', 'PEAK_MEASURE_RUN_ID' => 'imagebuild1'],
        );
        $this->assertSame(0, $built[0], $built[1] . $built[2]);
        $phpVersion = (string) (getenv('PHP_VERSION') ?: '8.3');

        $result = self::varianceRun('pair', ['--dbs=2', '--laps=1', '--parallel', '--php-image=peak-measure-php:' . $phpVersion]);
        $this->assertSame(0, $result[0], $result[1] . $result[2] . self::tail(self::$work . '/pair/db1/run.log') . self::tail(self::$work . '/pair/db2/run.log'));

        $one = self::$work . '/pair/db1';
        $two = self::$work . '/pair/db2';
        $this->assertSame(trim((string) file_get_contents($one . '/g1/content.sha256')), trim((string) file_get_contents($two . '/g1/content.sha256')), 'two databases built alike hold the same data');
        $this->assertSame((string) file_get_contents($one . '/g1/content.tsv'), (string) file_get_contents($two . '/g1/content.tsv'));
        $trialOne = self::trial((string) (glob($one . '/g1/plain_lap1/*') ?: [''])[0]);
        $trialTwo = self::trial((string) (glob($two . '/g1/plain_lap1/*') ?: [''])[0]);
        $this->assertNotSame($trialOne['database'], $trialTwo['database'], 'each database has its own run id');
        $this->assertSame(0, $trialOne['exit'] + $trialTwo['exit']);
        $this->assertFileExists($one . '/.done.g1');
        $this->assertFileDoesNotExist($one . '/alerts.log', 'a normal run says nothing');
        $this->assertFileDoesNotExist($two . '/alerts.log');
        $this->assertNoStackLeft('pair');
    }

    /**
     * @param list<string> $options
     *
     * @return resource the watch, started in the background
     */
    private static function startWatch(string $dir, string $db, array $options)
    {
        $process = proc_open(
            ['bash', self::runtimeDir() . '/bin/variance-watch', '--db-name=' . $db, '--out-dir=' . $dir, '--done-file=' . $dir . '/done', '--notify=' . self::$work . '/notify.sh', '--interval=1', ...$options],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(self::envOf(), static::$stackEnv),
        );
        self::assertIsResource($process);

        return $process;
    }

    /** @return array<string, string> */
    private static function envOf(): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            $env[(string) $key] = (string) $value;
        }

        return $env;
    }

    public function testTheWatchSaysSoWhenAStatementRunsTooLongAndWhenNothingMovesAndEndsWhenTheDoneFileAppears(): void
    {
        self::bringUp('-varwatch', 'measure.compose.yaml');
        $db = 'peak_sim_measure_wtest0001_1';
        $this->assertSame(0, self::rootSql('CREATE DATABASE `' . $db . '`')[0]);
        file_put_contents(self::$work . '/notify.sh', "#!/usr/bin/env bash\necho \"\$1\" >> " . self::$work . "/notified.txt\n");
        chmod(self::$work . '/notify.sh', 0755);

        // (i) a statement on the database that runs longer than the limit
        $dir = self::$work . '/watch-statement';
        mkdir($dir);
        $sleeper = proc_open(
            ['docker', 'compose', '-p', static::$project, '-f', self::runtimeDir() . '/compose/measure.compose.yaml', 'exec', '-T', '-e', 'MYSQL_PWD=' . static::$stackEnv['PEAK_MEASURE_DB_ROOT_PASSWORD'], 'measure-db', 'mysql', '-uroot', '-D', $db, '-e', 'SELECT SLEEP(14)'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $sleeperPipes,
            null,
            self::envOf(),
        );
        $this->assertIsResource($sleeper);
        $watch = self::startWatch($dir, $db, ['--statement-seconds=4', '--idle-seconds=3600']);
        sleep(9);
        touch($dir . '/done');
        $this->assertSame(0, proc_close($watch), 'the watch ends when the done file appears');
        proc_close($sleeper);
        $alerts = (string) file_get_contents($dir . '/alerts.log');
        $this->assertStringContainsString('a statement on ' . $db . ' has run', $alerts);
        $this->assertStringContainsString('SLEEP(14)', $alerts);
        $this->assertStringContainsString('(limit 4s)', $alerts);
        $this->assertSame(1, substr_count($alerts, 'has run'), 'said once for that statement');
        $this->assertStringContainsString('has run', (string) file_get_contents(self::$work . '/notified.txt'), 'the notifier got the message');

        // (ii) nothing runs on the database and nothing is written
        $dir = self::$work . '/watch-idle';
        mkdir($dir);
        $watch = self::startWatch($dir, $db, ['--statement-seconds=3600', '--idle-seconds=3']);
        sleep(8);
        touch($dir . '/done');
        $this->assertSame(0, proc_close($watch));
        $alerts = (string) file_get_contents($dir . '/alerts.log');
        $this->assertStringContainsString('no database statement and no output', $alerts);
        $this->assertSame(1, substr_count($alerts, 'no database statement'), 'said once per stall');
    }
}
