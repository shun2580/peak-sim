<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PeakSim\Runtime\Guard\StatementKiller;
use PeakSim\Runtime\Measure\BuildTimedOut;
use PeakSim\Runtime\Measure\DatabasePoint;
use PeakSim\Runtime\Measure\MeasureRequest;
use PeakSim\Runtime\Measure\Proc;
use PeakSim\Runtime\Measure\ProcessDatabaseBuilder;
use PeakSim\Runtime\Measure\ProcessOneRunner;
use PeakSim\Runtime\Run\Attempt;
use PeakSim\Runtime\Tests\Docker\Support\DockerCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A build or a measurement that runs past its limit is stopped, and so is the statement it left on the server.
 *
 * Killing the child process alone does not do it: the INSERT the server had started keeps running, holds the table's
 * metadata lock, and the DROP DATABASE that follows waits for it (a BookStack build once ran 4,000 seconds past its limit
 * this way). The children here are stand-ins (Support/slow_statement.php) whose statement cannot end within a test.
 */
#[Group('in-stack')]
final class TimedOutStatementDockerTest extends DockerCase
{
    private const SCRIPT = __DIR__ . '/Support/slow_statement.php';

    private const TAG = 'kt';

    /** The limit given to the stand-in: long enough for it to start its statement, short enough for a test. */
    private const LIMIT = 6;

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return ['a SLEEP inside an INSERT' => ['sleep'], 'a CROSS JOIN of three 3000-row tables' => ['crossjoin']];
    }

    /** @return array<string, string> */
    private static function env(string $kind): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            $env[(string) $key] = (string) $value;
        }

        return $env + ['SLOW_KIND' => $kind];
    }

    private static function databaseName(int $scale): string
    {
        $config = \PeakSim\Runtime\Config\MeasureConfig::fromEnv(['PEAK_MEASURE_DB_TAG' => self::TAG] + self::env('sleep'));

        return $config->dbNameForScale($scale);
    }

    private static function killer(): StatementKiller
    {
        return StatementKiller::connect(self::config());
    }

    protected function setUp(): void
    {
        self::requireInsideStack();
    }

    protected function tearDown(): void
    {
        // Whatever a failed assertion left behind.
        foreach ([3, 4, 5] as $scale) {
            try {
                self::killer()->killOn(self::databaseName($scale), 5);
                self::db()->drop(self::databaseName($scale));
            } catch (\Throwable) {
            }
        }
    }

    public function test_the_premise_killing_the_child_alone_leaves_the_statement_running_on_the_server(): void
    {
        $name = self::databaseName(3);
        [$code] = Proc::run([PHP_BINARY, self::SCRIPT, '--scale=3'], ['PEAK_MEASURE_DB_TAG' => self::TAG] + self::env('sleep'), self::LIMIT);

        $this->assertSame(Proc::TIMED_OUT, $code);
        $left = self::killer()->killOn($name, 20);
        $this->assertCount(1, $left['killed'], 'without the kill, the statement is still there after the child is gone');
        $this->assertSame('slow_t', $left['killed'][0]['table']);
    }

    #[DataProvider('kinds')]
    public function test_a_build_over_its_limit_is_stopped_with_its_statement_and_the_drop_is_not_blocked(string $kind): void
    {
        $point = new DatabasePoint(self::TAG, 4, ['kind' => $kind]);
        $name = self::databaseName(4);
        $builder = new ProcessDatabaseBuilder(self::SCRIPT, self::env($kind), 'generator', self::LIMIT);

        $started = microtime(true);
        try {
            $builder->build($point);
            $this->fail('the build should have timed out');
        } catch (BuildTimedOut $e) {
            $stopped = $e->stopped();
            $this->assertSame(self::LIMIT, $e->limitSeconds);
            $this->assertCount(1, $e->killed);
            $this->assertSame(0, $e->stillRunning, 'the statement ended after the kill');
            $this->assertSame('slow_t', $stopped['table'], 'the record names the table the statement was writing');
            $this->assertStringContainsStringIgnoringCase('INSERT INTO slow_t', (string) $stopped['statement']);
            $this->assertStringContainsString($kind === 'sleep' ? 'SLEEP(300)' : 'CROSS JOIN', (string) $stopped['statement']);
            $this->assertStringContainsString('slow_t', $e->getMessage());
        }
        $this->assertLessThan(self::LIMIT + 25, microtime(true) - $started, 'the kill did not take the time the statement would have');

        $this->assertSame([], self::killer()->killOn($name, 1)['killed'], 'nothing is left running on the database');

        $dropStarted = microtime(true);
        $builder->drop($point);
        $this->assertLessThan(10, microtime(true) - $dropStarted, 'the drop is not held by a lock the statement kept');
        $this->assertNotContains($name, self::db()->listMeasureDatabases());
    }

    public function test_a_measurement_over_its_limit_is_stopped_with_its_statement_and_the_database_can_be_dropped(): void
    {
        $name = self::databaseName(5);
        $runner = new ProcessOneRunner(self::SCRIPT, self::env('crossjoin'), self::LIMIT);
        $request = new MeasureRequest(self::TAG, 5, 'N', '/x', '/x', 'GET', null, null, false, false, [], false, [Attempt::WARMUP, Attempt::COLD]);

        $started = microtime(true);
        $result = $runner->run($request);

        $this->assertSame(Proc::TIMED_OUT, $result->exit);
        $this->assertStringContainsString('timed out after ' . self::LIMIT . ' seconds', $result->stderr);
        $this->assertStringContainsString('killed 1 statement(s) on the database', $result->stderr);
        $this->assertStringContainsString('slow_t', $result->stderr);
        $this->assertLessThan(self::LIMIT + 25, microtime(true) - $started);
        $this->assertSame([], self::killer()->killOn($name, 1)['killed']);

        $dropStarted = microtime(true);
        self::db()->drop($name);
        $this->assertLessThan(10, microtime(true) - $dropStarted);
    }

    public function test_the_kill_touches_only_the_named_database(): void
    {
        $other = self::databaseName(3);
        $mine = self::databaseName(4);
        $spawn = static function (int $scale): mixed {
            return proc_open([PHP_BINARY, self::SCRIPT, '--scale=' . $scale], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PEAK_MEASURE_DB_TAG' => self::TAG] + self::env('sleep'));
        };
        $a = $spawn(3);
        $b = $spawn(4);
        try {
            // Wait until both statements are running.
            $deadline = microtime(true) + 20;
            do {
                usleep(300_000);
                $both = self::countRunning($other) === 1 && self::countRunning($mine) === 1;
            } while (!$both && microtime(true) < $deadline);
            $this->assertTrue($both, 'both stand-ins started their statement');

            $result = self::killer()->killOn($mine, 20);

            $this->assertCount(1, $result['killed']);
            $this->assertSame(1, self::countRunning($other), 'the statement on the other database is still running');
        } finally {
            foreach ([$a, $b] as $process) {
                if (is_resource($process)) {
                    proc_terminate($process, 9); // our own test children only
                    proc_close($process);
                }
            }
            self::killer()->killOn($other, 5);
        }
    }

    private static function countRunning(string $database): int
    {
        $pdo = new \PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', self::config()->dbHost, self::config()->dbPort), self::config()->dbUser, self::config()->dbPassword, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $n = 0;
        foreach ($pdo->query('SHOW FULL PROCESSLIST')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (($row['db'] ?? null) === $database && ($row['Command'] ?? '') === 'Query') {
                ++$n;
            }
        }

        return $n;
    }
}
