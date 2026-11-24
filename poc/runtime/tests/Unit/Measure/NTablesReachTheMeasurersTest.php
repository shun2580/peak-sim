<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Explain\SharedTablesPlanner;
use PeakSim\Runtime\Measure\GeneratorPlanner;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Variance\TrialPlan;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `bin/capture` and `bin/explain-at-scale` build their database with `--n-tables` (the tables that grow together to N, one
 * database shared by every route). The two tools that measure on such a database, `bin/measure` and `bin/variance-run`,
 * have to take the same option, or the measured database is not the one the plans were taken on.
 *
 * These tests run the host-side scripts against a stand-in `docker` that only writes down what it was asked, and the
 * in-container planner (`bin/inner/variance-plan.php`) for real. Without the option nothing changes, to the byte.
 */
final class NTablesReachTheMeasurersTest extends PlannerTestCase
{
    private const US = "\x1f";

    /** @return \PeakSim\Runtime\Route\RouteSpec a route that scales on the one table */
    private static function specFor(string $uri, string $method, string $table): \PeakSim\Runtime\Route\RouteSpec
    {
        return self::spec($uri, ['n_table' => $table], $method);
    }

    private const TABLES = 'entities,images,references,zeta_rows';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-ntables-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/fake', 0775, true);
        mkdir($this->dir . '/app/vendor', 0775, true);
        touch($this->dir . '/app/vendor/autoload.php');
        file_put_contents($this->dir . '/static.json', '{}');
        file_put_contents($this->dir . '/tasks.json', '[]');
        // a stand-in docker: writes each call down (arguments separated by 0x1f), answers nothing, and fails the plan step
        file_put_contents($this->dir . '/fake/docker', <<<'SH'
            #!/usr/bin/env bash
            printf '%s\037' "$@" >> "$FAKE_DOCKER_RECORD"
            printf '\n' >> "$FAKE_DOCKER_RECORD"
            case "$*" in *variance-plan.php*) exit 1 ;; esac
            exit 0
            SH);
        chmod($this->dir . '/fake/docker', 0775);
        foreach (['measure', 'variance-run', '_stack.sh'] as $file) {
            copy(dirname(__DIR__, 3) . '/bin/' . $file, $this->dir . '/fake/' . $file);
        }
        file_put_contents($this->dir . '/fake/preflight', "#!/usr/bin/env bash\nexit 0\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, list<list<string>>} the exit code and the docker calls, each as its list of arguments
     */
    private function invoke(string $script, array $args, string $tag): array
    {
        $record = $this->dir . '/record-' . $tag . '.txt';
        $process = proc_open(['bash', $this->dir . '/fake/' . $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'PATH' => $this->dir . '/fake:/usr/bin:/bin', 'HOME' => $this->dir,
            'FAKE_DOCKER_RECORD' => $record, 'PEAK_OUT_DIR' => $this->dir . '/out-' . $tag, 'PEAK_COMPOSE_PROJECT' => 'peak-ntables-test',
            'PEAK_MEASURE_RUN_ID' => 'abc123def456', 'PEAK_MEASURE_DB_ROOT_PASSWORD' => 'x', 'PEAK_MEASURE_DB_PASSWORD' => 'y',
        ]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $calls = [];
        foreach (explode("\n", is_file($record) ? (string) file_get_contents($record) : '') as $line) {
            if ($line !== '') {
                $calls[] = explode(self::US, rtrim($line, self::US));
            }
        }
        self::assertGreaterThan(0, count($calls), $out . $err);

        return [$code, $calls];
    }

    /**
     * @param list<list<string>> $calls
     *
     * @return list<string> the arguments after the PHP script of the call that runs it
     */
    private static function argumentsOf(array $calls, string $script): array
    {
        foreach ($calls as $call) {
            $at = array_search($script, array_map(static fn (string $a): string => basename($a), $call), true);
            if ($at !== false && in_array('exec', $call, true)) {
                return array_slice($call, $at + 1);
            }
        }
        self::fail('no docker call ran ' . $script);
    }

    /** @return list<string> */
    private function measureArgs(): array
    {
        return ['--app=' . $this->dir . '/app', '--static=' . $this->dir . '/static.json', '--scales=240000', '--only=/books,/shelves', '--profile=bagisto', '--warm=off', '--request-timeout=600'];
    }

    /** @return list<string> */
    private function varianceArgs(string $tag): array
    {
        return [
            '--app=' . $this->dir . '/app', '--tasks=' . $this->dir . '/tasks.json', '--scale=240000', '--out=' . $this->dir . '/vout-' . $tag,
            '--label=run', '--routes=GET /books/{id},POST /shelves', '--dbs=1', '--laps=1', '--source-commit=abc1234', '--profile=bagisto',
        ];
    }

    public function test_bin_measure_without_the_option_passes_the_same_arguments_as_before(): void
    {
        [$code, $calls] = $this->invoke('measure', $this->measureArgs(), 'plain');

        $this->assertSame(0, $code);
        $this->assertSame(['--data=generator', '--only=/books,/shelves', '--profile=bagisto', '--warm=off'], self::argumentsOf($calls, 'measure.php'));
    }

    public function test_bin_measure_hands_the_option_to_the_driver_and_changes_nothing_else(): void
    {
        [, $plain] = $this->invoke('measure', $this->measureArgs(), 'plain');
        [$code, $shared] = $this->invoke('measure', [...$this->measureArgs(), '--n-tables=' . self::TABLES], 'shared');

        $this->assertSame(0, $code);
        $this->assertSame(
            ['--data=generator', '--only=/books,/shelves', '--n-tables=' . self::TABLES, '--profile=bagisto', '--warm=off'],
            self::argumentsOf($shared, 'measure.php'),
        );
        // every other call of docker is the same as without the option
        $this->assertSame(
            $plain,
            array_map(static fn (array $c): array => array_values(array_filter($c, static fn (string $a): bool => !str_starts_with($a, '--n-tables='))), $shared),
        );
    }

    public function test_variance_run_without_the_option_asks_for_the_plan_as_before_and_writes_the_same_record(): void
    {
        [, $calls] = $this->invoke('variance-run', $this->varianceArgs('plain'), 'plain');

        $this->assertSame(
            ['--tasks=/out/tasks.json', '--routes=GET /books/{id},POST /shelves', '--scale=240000', '--data=generator', '--out-dir=/out', '--profile=bagisto'],
            self::argumentsOf($calls, 'variance-plan.php'),
        );
        $this->assertStringNotContainsString('n_tables', (string) file_get_contents($this->dir . '/vout-plain/run/run.txt'));
    }

    public function test_variance_run_hands_the_option_to_the_planner_and_writes_it_down(): void
    {
        [, $plain] = $this->invoke('variance-run', $this->varianceArgs('plain'), 'plain');
        [, $shared] = $this->invoke('variance-run', [...$this->varianceArgs('shared'), '--n-tables=' . self::TABLES], 'shared');

        $this->assertSame(
            ['--tasks=/out/tasks.json', '--routes=GET /books/{id},POST /shelves', '--scale=240000', '--data=generator', '--out-dir=/out', '--profile=bagisto', '--n-tables=' . self::TABLES],
            self::argumentsOf($shared, 'variance-plan.php'),
        );
        // every call of docker is the same as without the option, but the plan call, which has the one argument more
        $this->assertSame(
            $plain,
            array_map(static fn (array $c): array => array_values(array_filter($c, static fn (string $a): bool => !str_starts_with($a, '--n-tables='))), $shared),
        );
        $this->assertSame([...$this->recordLines('plain'), 'n_tables=' . self::TABLES], $this->recordLines('shared'));
    }

    /** @return list<string> the lines of a run's run.txt that do not hold a time or the label */
    private function recordLines(string $label): array
    {
        return array_values(array_filter(
            explode("\n", trim((string) file_get_contents($this->dir . '/vout-' . $label . '/run/run.txt'))),
            static fn (string $l): bool => !str_starts_with($l, 'started=') && !str_starts_with($l, 'finished='),
        ));
    }

    /**
     * @param list<string> $extra
     *
     * @return array{list<array<string, mixed>>, string} the plan files' groups (decoded plan.json) and the directory they are in
     */
    private function planWith(array $extra, string $tag): array
    {
        $tasks = [];
        foreach ([['/books/{id}', 'DELETE', '/books/1', 'books'], ['/shelves/{slug}', 'DELETE', '/shelves/1', 'shelves'], ['/pages/{id}', 'GET', '/pages/1', 'entities']] as [$uri, $method, $filled, $table]) {
            $tasks[] = (new RouteTask(
                self::specFor($uri, $method, $table), $filled, null, null, 'App\\Models\\User:1', [], false, true,
            ))->toArray();
        }
        $plan = $this->dir . '/plan-' . $tag;
        mkdir($plan, 0775, true);
        file_put_contents($this->dir . '/tasks-' . $tag . '.json', json_encode($tasks, JSON_THROW_ON_ERROR));
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__, 3) . '/bin/inner/variance-plan.php', '--tasks=' . $this->dir . '/tasks-' . $tag . '.json',
                '--routes=DELETE /books/{id},DELETE /shelves/{slug},GET /pages/{id}', '--scale=240000', '--data=generator', '--out-dir=' . $plan, ...$extra],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $out . $err);

        return [json_decode((string) file_get_contents($plan . '/plan.json'), true, 512, JSON_THROW_ON_ERROR), $plan];
    }

    public function test_the_planner_without_the_option_gives_each_route_the_database_of_its_own_table(): void
    {
        [$groups] = $this->planWith([], 'own');

        $this->assertCount(3, $groups, 'three routes, three tables, three databases: the plan of before');
    }

    public function test_the_planner_with_the_option_builds_one_database_for_every_route_with_the_tables_of_the_capture_and_the_explain(): void
    {
        [$groups] = $this->planWith(['--n-tables=' . self::TABLES], 'shared');

        $this->assertCount(1, $groups);
        $this->assertCount(3, $groups[0]['routes']);
        $recipe = ['mode' => 'targeted', 'n_tables' => ['entities', 'images', 'references', 'zeta_rows'], 'm_table' => null, 'm_per' => null, 'n' => 240000, 'm' => 0, 'base' => 5];
        $this->assertSame(['--scale=240000', '--mode=generator', '--recipe-json=' . json_encode($recipe)], $groups[0]['build_args']);

        // the plan of bin/explain-at-scale for the same tables: the same recipe, so the same database
        $explain = (new SharedTablesPlanner(explode(',', self::TABLES)))->sweepsFor(self::specFor('/pages/{id}', 'GET', 'entities'), [240000]);
        $this->assertSame($recipe, $explain[0]->points[0]->recipe);
        // and the plan of the driver (the one bin/capture reaches through bin/inner/measure.php)
        $driver = (new GeneratorPlanner(GeneratorPlanner::BASE, explode(',', self::TABLES)))->sweepsFor(self::specFor('/books/{id}', 'DELETE', 'books'), [240000]);
        $this->assertSame($recipe, $driver[0]->points[1]->recipe);
    }

    public function test_the_order_the_tables_are_named_in_does_not_change_the_database(): void
    {
        [$a] = $this->planWith(['--n-tables=zeta_rows,entities,references,images'], 'order_a');
        [$b] = $this->planWith(['--n-tables=' . self::TABLES], 'order_b');

        $this->assertSame($a[0]['build_args'], $b[0]['build_args']);
        $this->assertSame($a[0]['tag'], $b[0]['tag']);
    }

    public function test_the_shared_plan_is_the_one_trial_plan_makes_from_the_driver_planner(): void
    {
        $tasks = array_map(
            static fn (array $r): RouteTask => new RouteTask(self::specFor($r[0], $r[1], $r[3]), $r[2], null, null, 'App\\Models\\User:1', [], false, true),
            [['/books/{id}', 'DELETE', '/books/1', 'books'], ['/pages/{id}', 'GET', '/pages/1', 'entities']],
        );
        $plan = TrialPlan::build($tasks, ['DELETE /books/{id}', 'GET /pages/{id}'], 240000, new GeneratorPlanner(GeneratorPlanner::BASE, explode(',', self::TABLES)), 'generator');

        $this->assertCount(1, $plan);
        $this->assertCount(2, $plan[0]['routes']);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function badPlannerArguments(): iterable
    {
        yield 'a capital' => [['--n-tables=Entities'], 'table names'];
        yield 'an empty name' => [['--n-tables=a,,b'], 'table names'];
        yield 'empty' => [['--n-tables='], 'table names'];
        yield 'with the seeder' => [['--data=seeder', '--n-tables=entities'], 'generator only'];
    }

    /**
     * @param list<string> $extra
     */
    #[DataProvider('badPlannerArguments')]
    public function test_the_planner_refuses_a_wrong_option_with_exit_2(array $extra, string $message): void
    {
        file_put_contents($this->dir . '/tasks-bad.json', '[]');
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/inner/variance-plan.php', '--tasks=' . $this->dir . '/tasks-bad.json', '--routes=GET /a', '--scale=1000', ...$extra],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(2, proc_close($process), $err);
        $this->assertStringContainsString($message, $err);
    }
}
