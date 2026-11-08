<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PDO;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Explain\CaptureFile;
use PeakSim\Runtime\Explain\ExplainDb;
use PeakSim\Runtime\Explain\ExplainReport;
use PeakSim\Runtime\Explain\ExplainRun;
use PeakSim\Runtime\Explain\ExplainSender;
use PeakSim\Runtime\Explain\PdoExplainTransport;
use PeakSim\Runtime\Explain\PdoStatsDb;
use PeakSim\Runtime\Explain\PlanSummary;
use PeakSim\Runtime\Explain\StatsStatements;
use PeakSim\Runtime\Tests\Docker\Support\DockerCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The plans of statements on a real server, inside the sealed container, on two databases made here by hand (a small one of
 * 40 rows and a large one of 20,000 rows of the same table), so the plans that differ do so for a reason the test built:
 *
 *  - `select * from t where a >= 39`: 2 of the 40 rows (an index range), but nearly every row of the large table (a full scan)
 *    -> the plan changes with the scale; `a = 3` is an index lookup in both -> it does not.
 *  - an EXPLAIN of a DELETE and of an UPDATE changes no row; the same statements sent without EXPLAIN are refused before they
 *    are sent; and a second statement is refused by the server itself even when the gate is walked around (the PDO transport
 *    uses real prepared statements).
 *  - the three statistics states are made on the large table (the table options say so, and what the server sampled is
 *    ordered), and the marks agree with an independent comparison of the raw plans.
 *  - the `measure` user can do the statements of `StatsStatements` (its privileges on the measurement databases) and cannot read
 *    the `mysql` statistics tables: that is why `bin/explain-at-scale` copies them as the server's root account, host side, and
 *    why nothing was added to the user's privileges.
 */
#[Group('in-stack')]
final class ExplainAtScaleInStackTest extends DockerCase
{
    private const SMALL = 1000;

    private const LARGE = 10000;

    private const SMALL_ROWS = 40;

    private const LARGE_ROWS = 20000;

    private static ?PDO $small = null;

    private static ?PDO $large = null;

    public static function setUpBeforeClass(): void
    {
        self::requireInsideStack();
        foreach ([[self::SMALL, self::SMALL_ROWS], [self::LARGE, self::LARGE_ROWS]] as [$scale, $rows]) {
            $name = self::config()->dbNameForScale($scale);
            self::db()->drop($name);
            self::db()->create($name);
            $pdo = ExplainDb::open(self::config(), $name);
            $pdo->exec('CREATE TABLE t (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, a INT NOT NULL, b VARCHAR(40) NOT NULL, KEY t_a (a))');
            $pdo->exec('SET SESSION cte_max_recursion_depth = 100000');
            $pdo->exec("INSERT INTO t (a, b) WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < $rows) SELECT n, CONCAT('row-', n) FROM s");
            $stats = new StatsStatements(new PdoStatsDb($pdo));
            $stats->analyze($stats->tables());
            if ($scale === self::SMALL) {
                self::$small = $pdo;
            } else {
                self::$large = $pdo;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$small = self::$large = null;
        self::drop(self::SMALL);
        self::drop(self::LARGE);
    }

    private static function pdo(int $scale): PDO
    {
        $pdo = $scale === self::SMALL ? self::$small : self::$large;
        self::assertNotNull($pdo);

        return $pdo;
    }

    /** @return array{rows: int, checksum: string} */
    private static function content(PDO $pdo): array
    {
        $rows = (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
        $sum = $pdo->query('CHECKSUM TABLE t')->fetch(PDO::FETCH_NUM);

        return ['rows' => $rows, 'checksum' => (string) $sum[1]];
    }

    /**
     * @param list<array{id: string, kind: string, sql: string, bindings: list<mixed>}> $types
     *
     * @return list<array<string, mixed>>
     */
    private static function statements(string $route, array $types): array
    {
        $capture = ['routes' => [['route' => $route, 'statement_types' => array_map(static fn (array $t): array => [
            'id' => $t['id'], 'kind' => $t['kind'], 'pattern' => $t['sql'],
            'representative' => ['scale' => 10000, 'sql' => $t['sql'], 'bindings' => $t['bindings']],
        ], $types)]]];

        return CaptureFile::read($capture)['statements'];
    }

    public function testAnExplainOfADeleteAndOfAnUpdateChangesNoRow(): void
    {
        $pdo = self::pdo(self::LARGE);
        $before = self::content($pdo);
        $sender = new ExplainSender(new PdoExplainTransport($pdo));

        $delete = $sender->explain('delete from t where a >= ?', [1]);
        $update = $sender->explain('update t set b = ? where a >= ?', ['changed', 1]);

        $this->assertTrue($delete->isAvailable(), (string) $delete->unavailable);
        $this->assertTrue($update->isAvailable(), (string) $update->unavailable);
        $this->assertSame($before, self::content($pdo), 'nothing was deleted or updated');
        $this->assertSame(self::LARGE_ROWS, $before['rows']);
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM t WHERE b = 'changed'")->fetchColumn());
    }

    public function testADeleteAndAnUpdateSentWithoutExplainAreRefusedBeforeTheServerSeesThemAndNoRowChanges(): void
    {
        $pdo = self::pdo(self::LARGE);
        $before = self::content($pdo);
        $sender = new ExplainSender(new PdoExplainTransport($pdo));

        foreach ([['delete from t where a >= ?', [1]], ['update t set b = ? where a >= ?', ['changed', 1]]] as [$sql, $bindings]) {
            try {
                $sender->sendRaw($sql, $bindings);
                $this->fail('sent without EXPLAIN: ' . $sql);
            } catch (MeasureRefused $e) {
                $this->assertStringContainsString('EXPLAIN FORMAT=JSON', $e->getMessage());
            }
        }

        $this->assertSame(0, $sender->sent());
        $this->assertSame($before, self::content($pdo));
    }

    public function testASecondStatementIsRefusedByTheServerEvenWhenTheGateIsWalkedAround(): void
    {
        $pdo = self::pdo(self::LARGE);
        $before = self::content($pdo);
        $transport = new PdoExplainTransport($pdo); // the transport alone: no gate in front of it

        $failed = false;
        try {
            $transport->fetchPlanJson('EXPLAIN FORMAT=JSON select 1; delete from t', []);
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'a prepared statement of the server takes one statement');
        $this->assertSame($before, self::content($pdo));
    }

    public function testThePlanThatChangesWithTheScaleIsMarkedAndTheOneThatDoesNotIsNot(): void
    {
        $types = [
            ['id' => 'flip', 'kind' => 'select', 'sql' => 'select * from t where a >= ?', 'bindings' => [39]],
            ['id' => 'same', 'kind' => 'select', 'sql' => 'select * from t where a = ?', 'bindings' => [3]],
        ];
        $statements = self::statements('GET /t', $types);
        $small = ExplainRun::run(new ExplainSender(new PdoExplainTransport(self::pdo(self::SMALL))), $statements, ['GET /t']);
        $large = ExplainRun::run(new ExplainSender(new PdoExplainTransport(self::pdo(self::LARGE))), $statements, ['GET /t']);

        $report = ExplainReport::assemble(
            ['scale' => self::LARGE_ROWS, 'small_scale' => self::SMALL_ROWS],
            $statements,
            [
                ['role' => 'small', 'scale' => self::SMALL_ROWS, 'database' => 's', 'routes' => ['GET /t'], 'results' => ['default' => $small]],
                ['role' => 'target', 'scale' => self::LARGE_ROWS, 'database' => 'l', 'routes' => ['GET /t'], 'results' => ['default' => $large, 'low' => $large, 'high' => $large]],
            ],
            [],
            [],
        );
        $byId = [];
        foreach ($report['routes'][0]['statements'] as $s) {
            $byId[$s['id']] = $s;
        }

        $this->assertSame('t|range|t_a', $byId['flip']['small_scale']['default']['summary']['signature']);
        $this->assertSame('t|ALL|-', $byId['flip']['target_scale']['default']['summary']['signature']);
        $this->assertTrue($byId['flip']['marks']['plan_changes_at_scale']);
        $this->assertFalse($byId['flip']['marks']['plan_unstable'], 'the same plan in every state');
        $this->assertSame('t|ref|t_a', $byId['same']['small_scale']['default']['summary']['signature']);
        $this->assertSame('t|ref|t_a', $byId['same']['target_scale']['default']['summary']['signature']);
        $this->assertFalse($byId['same']['marks']['plan_changes_at_scale']);
        $this->assertSame(1, $report['totals']['plan_changes_at_scale']);
        $this->assertArrayHasKey('raw', $byId['flip']['target_scale']['default'], 'the server\'s own JSON is kept');
        $estimate = $byId['flip']['target_scale']['default']['summary']['tables'][0]['rows_examined_per_scan'];
        $this->assertGreaterThan(15000, $estimate, 'a scan of the large table is estimated near its size');
        $this->assertLessThan(25000, $estimate);
    }

    public function testTheStatesAreMadeOnTheTableAndTheMarkAgreesWithTheRawPlans(): void
    {
        $pdo = self::pdo(self::LARGE);
        $stats = new StatsStatements(new PdoStatsDb($pdo));
        $relevant = $stats->relevant(['t']);
        $this->assertSame(['t'], $relevant);
        $stats->analyze($stats->tables());
        $stats->switchAutoRecalcOff($relevant);
        $statements = self::statements('GET /t', [
            ['id' => 'q1', 'kind' => 'select', 'sql' => 'select * from t where a >= ? and b = ?', 'bindings' => [10, 'row-11']],
            ['id' => 'q2', 'kind' => 'select', 'sql' => 'select * from t where a between ? and ?', 'bindings' => [100, 5000]],
        ]);

        $results = [];
        foreach (StatsStatements::SAMPLE_PAGES as $state => $pages) {
            $stats->setSamplePages($relevant, $pages);
            $stats->analyze($relevant);
            $options = strtolower((string) $pdo->query("SELECT create_options FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = 't'")->fetchColumn());
            $this->assertStringContainsString('stats_sample_pages=' . $pages, $options);
            $this->assertStringContainsString('stats_auto_recalc=0', $options);
            $results[$state] = ExplainRun::run(new ExplainSender(new PdoExplainTransport($pdo)), $statements, ['GET /t']);
        }

        $report = ExplainReport::assemble(['scale' => self::LARGE_ROWS, 'small_scale' => self::SMALL_ROWS], $statements, [
            ['role' => 'target', 'scale' => self::LARGE_ROWS, 'database' => 'l', 'routes' => ['GET /t'], 'results' => $results],
        ], [], []);
        foreach ($report['routes'][0]['statements'] as $i => $s) {
            $signatures = [];
            foreach (array_keys(StatsStatements::SAMPLE_PAGES) as $state) {
                $this->assertArrayHasKey('summary', $s['target_scale'][$state], 'every state has a plan');
                $signatures[] = PlanSummary::signature($s['target_scale'][$state]['summary']['tables']);
            }
            $this->assertSame(count(array_unique($signatures)) > 1, $s['marks']['plan_unstable'], 'statement ' . $i . ': the mark is the comparison of the raw plans');
            $this->assertSame(['default', 'low', 'high'], array_keys($s['target_scale']));
        }
    }

    public function testTheMeasureUserCanAnalyzeAndAlterTheMeasurementDatabaseButCannotReadTheStatisticsTables(): void
    {
        $pdo = self::pdo(self::SMALL);
        $stats = new StatsStatements(new PdoStatsDb($pdo));

        $messages = $stats->analyze(['t']);
        $this->assertSame('OK', (string) ($messages['t'][0]['Msg_text'] ?? ''));
        $stats->switchAutoRecalcOff(['t']);
        $stats->setSamplePages(['t'], 20);

        $denied = null;
        try {
            $pdo->query('SELECT COUNT(*) FROM mysql.innodb_table_stats')->fetchAll();
        } catch (\PDOException $e) {
            $denied = $e->getMessage();
        }
        $this->assertNotNull($denied, 'the measure user must not read mysql.innodb_table_stats (its privileges are not widened)');
        $this->assertMatchesRegularExpression('/denied|1142|1044/', (string) $denied);
    }
}
