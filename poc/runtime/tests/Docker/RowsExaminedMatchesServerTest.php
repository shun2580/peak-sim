<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PeakSim\Runtime\Tests\Docker\Support\DockerCase;
use PeakSim\Runtime\Tests\Docker\Support\ExcludedStatementTypes;
use PeakSim\Runtime\Tests\Docker\Support\RowsExaminedVerdict;
use PHPUnit\Framework\Attributes\Group;

/**
 * Checks the "rows examined" figure of a request (the growth of the session's Handler_read_* counters, see
 * `Run/RowsExamined`) against what the database server itself counted for the same statements:
 * `performance_schema.events_statements_history_long`, column ROWS_EXAMINED, summed over the statements of the same
 * thread that ran between the two snapshots (the server keeps no text for prepared statements, so the window is found by
 * its place between the transaction's START TRANSACTION and ROLLBACK).
 *
 * The server's side is read from a SEPARATE connection of this test, never from the app's connection. The two numbers
 * are counted by different mechanisms (storage-engine handler calls against the optimizer's per-statement count). The
 * handler's count is the one that is kept (it is what the database spends its CPU on). Three types of statement are
 * known to differ for a reason, and are left out of BOTH sides before the two are compared: a `count(*)` over a whole
 * table, the end-of-range read of a range read of an index, and a look-up by a unique index that finds nothing. Which
 * statement is of which type is decided by a rule that reads the statement's text and its plan, never by the route's
 * name (see `Support/ExcludedStatementTypes`). A fourth type is handled the other way round: a scan of a whole table or
 * index (access type ALL or index) whose handler count is 1 to 3 rows above the server's keeps its place in the comparison,
 * and only that small difference (the read that finds the end of the scan) is taken off the app's count. The cap of 3 is
 * fixed, and a scan statement over it fails the route whatever the total looks like. The figure is accepted when every
 * route is within 10% of the server's count on what is left. The limit is not moved to make a route pass. The verdict
 * itself is `Support/RowsExaminedVerdict`, bound by a unit test with expectations written by hand.
 *
 * It runs `bin/measure-one` for the cold attempt of every route of the verification app (so the real wiring is what is
 * checked), then finds that process's thread and the window between its two snapshots in the server's history. What
 * each statement said and read on the app's side is not visible from there, so the same request is sent once more
 * through `Support/tap_statements.php`, which reads the counters statement by statement; the reads of the left-out
 * statements are taken off the figure of the run that is checked, and the figure must equal the sum of the tap's (a
 * route whose figure has a remainder that the tap does not explain fails).
 */
#[Group('in-stack')]
final class RowsExaminedMatchesServerTest extends DockerCase
{
    private const SCALE = 1000;

    /** customers, products, orders, items per order: large enough that a handful of rows of noise stays under 10% */
    private const SIZES = [100, 100, 500, 3];

    private const LIMIT = 0.10;

    /** @var list<array{method: string, uri: string}> */
    private static array $routes = [];

    public static function setUpBeforeClass(): void
    {
        self::requireInsideStack();
        self::prepare(self::SCALE, self::SIZES);
        [$exit, $out, $err] = self::php(__DIR__ . '/Support/list_routes.php', [(string) self::SCALE]);
        self::assertSame(0, $exit, $err);
        self::$routes = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function tearDownAfterClass(): void
    {
        self::drop(self::SCALE);
    }

    private static function server(): \PDO
    {
        $c = self::config();

        return new \PDO(
            sprintf('mysql:host=%s;port=%d', $c->dbHost, $c->dbPort),
            $c->dbUser,
            $c->dbPassword,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    public function testTheMeasureUserCanReadOnlyTheTwoPerformanceSchemaTablesThatTheCheckNeeds(): void
    {
        $grants = array_map('current', self::server()->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(\PDO::FETCH_NUM));

        $performanceSchema = array_values(array_filter($grants, static fn (string $g): bool => str_contains($g, '`performance_schema`')));
        sort($performanceSchema);
        self::assertCount(2, $performanceSchema, implode("\n", $grants));
        self::assertMatchesRegularExpression('/^GRANT SELECT ON `performance_schema`\.`events_statements_history_long` TO /', $performanceSchema[0]);
        self::assertMatchesRegularExpression('/^GRANT SELECT ON `performance_schema`\.`threads` TO /', $performanceSchema[1]);
        foreach ($grants as $grant) {
            if (str_contains($grant, '`performance_schema`')) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/^GRANT (USAGE ON \*\.\*|ALL PRIVILEGES ON `peak\\\\_sim\\\\_measure\\\\_%`\.\*) TO /',
                $grant,
                'a grant beyond the measurement databases and the two reads',
            );
        }
    }

    public function testTheRuleNamesTheTypeOfAStatementFromItsTextAndItsPlanOnly(): void
    {
        $plan = static fn (?string $type, ?string $extra = null): array => [['type' => $type, 'extra' => $extra]];

        // count_all: the text, whatever the plan says
        self::assertSame('count_all', ExcludedStatementTypes::of('select count(*) as `aggregate` from `orders`', $plan('index', 'Using index')));
        self::assertNull(ExcludedStatementTypes::of('select count(*) as `aggregate` from `items` where `items`.`order_id` = ?', $plan('const')), 'a count with a WHERE is not a count of the whole table');
        // range_end: a range read of an index, also inside a subquery of a statement
        self::assertSame('range_end', ExcludedStatementTypes::of('select * from `items` where `order_id` = ?', $plan('ref')));
        self::assertSame('range_end', ExcludedStatementTypes::of('select * from `items` where `qty` > ?', $plan('range', 'Using index condition')));
        self::assertSame('range_end', ExcludedStatementTypes::of('select `orders`.*, (select count(*) from `items` where ...) from `orders`', [['type' => 'ALL', 'extra' => null], ['type' => 'ref', 'extra' => null]]));
        // unique_miss: the server's own plan says the unique look-up finds nothing
        self::assertSame('unique_miss', ExcludedStatementTypes::of('select * from `product_stats` where `product_id` = ? limit 1', $plan(null, 'no matching row in const table')));
        self::assertSame('unique_miss', ExcludedStatementTypes::of('select * from `t` where `u` = ?', $plan(null, 'Impossible WHERE noticed after reading const tables')));
        // compared: a look-up by a unique index that finds a row, a scan of the whole table or index, a write, a statement with no plan
        self::assertNull(ExcludedStatementTypes::of('select * from `customers` where `id` = ? limit 1', $plan('const')));
        self::assertNull(ExcludedStatementTypes::of('select * from `customers` where `id` = ?', $plan('eq_ref')));
        self::assertNull(ExcludedStatementTypes::of('select * from `orders`', $plan('ALL')));
        self::assertNull(ExcludedStatementTypes::of('select * from `orders` order by `id` limit 100', $plan('index')));
        self::assertNull(ExcludedStatementTypes::of('update `customers` set `name` = ? where `id` = ?', null));
        self::assertNull(ExcludedStatementTypes::of('select * from `orders` where `id` in (1,2)', ['error' => 'could not explain']));
    }

    public function testTheScanEndRuleTakesOffOneToThreeRowsOfAScanAndNothingElse(): void
    {
        $scan = static fn (string $type): array => [['type' => $type, 'extra' => null]];
        $sql = 'select * from `orders` limit 15';
        $surplus = static fn (?array $plan, int $handler, int $server, string $text = 'select * from `orders` limit 15'): int => ExcludedStatementTypes::scanEndSurplus($text, $plan, $handler, $server);

        self::assertSame(3, ExcludedStatementTypes::SCAN_END_MAX, 'the cap is fixed by ruling');
        // a scan (ALL or index): the surplus of 1, 2 and 3 is taken off; none when equal; 4 is not taken off (and is over the cap)
        foreach (['ALL', 'index'] as $type) {
            self::assertSame(0, $surplus($scan($type), 15, 15), $type . ': difference 0');
            self::assertSame(1, $surplus($scan($type), 16, 15), $type . ': difference 1');
            self::assertSame(2, $surplus($scan($type), 17, 15), $type . ': difference 2');
            self::assertSame(3, $surplus($scan($type), 18, 15), $type . ': difference 3');
            self::assertSame(0, $surplus($scan($type), 19, 15), $type . ': difference 4 is not taken off');
            self::assertFalse(ExcludedStatementTypes::scanEndOverCap($sql, $scan($type), 18, 15), $type . ': difference 3 is within the cap');
            self::assertTrue(ExcludedStatementTypes::scanEndOverCap($sql, $scan($type), 19, 15), $type . ': difference 4 is over the cap');
            self::assertSame(0, $surplus($scan($type), 14, 15), $type . ': the server counted more than the engine: nothing is taken off');
        }
        // not a scan: a unique look-up, an eq_ref join, a statement with no plan, a plan that could not be taken
        self::assertSame(0, $surplus($scan('const'), 17, 15));
        self::assertSame(0, $surplus($scan('eq_ref'), 17, 15));
        self::assertSame(0, $surplus(null, 17, 15));
        self::assertSame(0, $surplus(['error' => 'could not explain'], 17, 15));
        // a scan and a join by a unique key: the plan has a scan, so the rule applies
        self::assertSame(2, $surplus([['type' => 'ALL', 'extra' => null], ['type' => 'eq_ref', 'extra' => null]], 17, 15));
        // a statement of one of the three left-out types is never adjusted (it is left out whole)
        self::assertSame(0, $surplus([['type' => 'ALL', 'extra' => null], ['type' => 'ref', 'extra' => null]], 17, 15), 'range_end first');
        self::assertSame(0, $surplus($scan('index'), 17, 15, 'select count(*) as `aggregate` from `orders`'), 'count_all first');
        self::assertFalse(ExcludedStatementTypes::scanEndOverCap('select count(*) as `aggregate` from `orders`', $scan('index'), 900, 0), 'a left-out statement is not counted as over the cap');
    }

    public function testEveryRouteOfTheVerificationAppExaminesWhatTheServerCounted(): void
    {
        self::assertGreaterThanOrEqual(10, count(self::$routes), 'the verification app has more than ten routes without a parameter');

        $table = [];
        $failures = [];
        foreach (self::$routes as $route) {
            $row = $this->compare($route['method'], $route['uri']);
            $table[] = $row;
            if ($row['verdict'] !== 'ok') {
                $failures[] = sprintf('%s %s: %s', $route['method'], $route['uri'], $row['verdict']);
            }
        }

        $this->writeEvidence($table);
        self::assertSame([], $failures, "rows examined differs from the server's count:\n" . self::render($table));
    }

    /**
     * @return array{route: string, class: ?string, queries: ?int, returned: ?int, examined: ?int, baseline: ?int, server: ?int, statements: ?int, excluded: array<string, int>, scan_end: int, scan_end_rows: int, scan_over_cap: int, unaccounted: ?int, left_statements: ?int, left_examined: ?int, left_server: ?int, diff: ?float, verdict: string}
     */
    private function compare(string $method, string $uri): array
    {
        $row = ['route' => $method . ' ' . $uri, 'class' => null, 'queries' => null, 'returned' => null, 'examined' => null, 'baseline' => null, 'server' => null, 'statements' => null, 'excluded' => [], 'scan_end' => 0, 'scan_end_rows' => 0, 'scan_over_cap' => 0, 'unaccounted' => null, 'left_statements' => null, 'left_examined' => null, 'left_server' => null, 'diff' => null, 'verdict' => ''];

        $server = self::server();
        $previous = (int) $server->query(
            "select coalesce(max(THREAD_ID), 0) from performance_schema.events_statements_history_long where EVENT_NAME = 'statement/sql/begin'",
        )->fetchColumn();

        $args = ['--scale=' . self::SCALE, '--uri=' . $uri, '--method=' . $method, '--phases=cold'];
        if ($method !== 'GET') {
            $args[] = '--csrf';
        }
        [$exit, $attempts, $err] = self::measure($args);
        if ($exit !== 0 || count($attempts) !== 1) {
            $row['verdict'] = sprintf('measure-one gave exit %d and %d attempts: %s', $exit, count($attempts), substr($err, 0, 200));

            return $row;
        }
        $attempt = $attempts[0];
        $row['class'] = $attempt['class'] ?? null;
        $row['queries'] = $attempt['queries'] ?? null;
        $row['returned'] = isset($attempt['rows_read']) ? array_sum($attempt['rows_read']) : null;
        $row['examined'] = $attempt['rows_examined'] ?? null;
        $row['baseline'] = $attempt['rows_examined_baseline'] ?? null;
        if ($row['examined'] === null) {
            $row['verdict'] = 'rows_examined was not measured (null)';

            return $row;
        }

        // The app sends its statements as prepared ones, whose text the server does not keep, so the snapshots are found
        // by their place: the run's thread is the newest one that began a transaction; the request ran between its
        // START TRANSACTION and its ROLLBACK; the first and the last execution in between are the two snapshots.
        $thread = (int) $server->query(
            "select coalesce(max(THREAD_ID), 0) from performance_schema.events_statements_history_long where EVENT_NAME = 'statement/sql/begin'",
        )->fetchColumn();
        if ($thread <= $previous) {
            $row['verdict'] = 'the statements of this run were not found in the server history (is the consumer on, is the buffer big enough?)';

            return $row;
        }
        $bounds = $server->prepare(
            "select min(case when EVENT_NAME = 'statement/sql/begin' then EVENT_ID end), max(case when EVENT_NAME = 'statement/sql/rollback' then EVENT_ID end)"
            . ' from performance_schema.events_statements_history_long where THREAD_ID = :t',
        );
        $bounds->execute(['t' => $thread]);
        [$begin, $rollback] = $bounds->fetch(\PDO::FETCH_NUM);
        if ($begin === null || $rollback === null) {
            $row['verdict'] = 'no START TRANSACTION / ROLLBACK pair of this run in the server history';

            return $row;
        }
        $executions = $server->prepare(
            "select EVENT_ID, ROWS_SENT from performance_schema.events_statements_history_long where THREAD_ID = :t and EVENT_NAME = 'statement/com/Execute'"
            . ' and EVENT_ID > :a and EVENT_ID < :b order by EVENT_ID',
        );
        $executions->execute(['t' => $thread, 'a' => $begin, 'b' => $rollback]);
        $executions = $executions->fetchAll(\PDO::FETCH_ASSOC);
        if (count($executions) < 2 || (int) $executions[0]['ROWS_SENT'] !== 7 || (int) $executions[count($executions) - 1]['ROWS_SENT'] !== 7) {
            $row['verdict'] = 'the two snapshots (7 rows each: the seven Handler_read counters) were not found at the ends of the transaction';

            return $row;
        }
        $first = (int) $executions[0]['EVENT_ID'];
        $last = (int) $executions[count($executions) - 1]['EVENT_ID'];

        $found = $server->prepare(
            'select count(*), coalesce(sum(ROWS_EXAMINED), 0) from performance_schema.events_statements_history_long'
            . ' where THREAD_ID = :t and EVENT_ID > :a and EVENT_ID < :b',
        );
        $found->execute(['t' => $thread, 'a' => $first, 'b' => $last]);
        [$statements, $sum] = array_map('intval', $found->fetch(\PDO::FETCH_NUM));
        $each = $server->prepare(
            "select ROWS_EXAMINED from performance_schema.events_statements_history_long where THREAD_ID = :t and EVENT_NAME = 'statement/com/Execute'"
            . ' and EVENT_ID > :a and EVENT_ID < :b order by EVENT_ID',
        );
        $each->execute(['t' => $thread, 'a' => $first, 'b' => $last]);
        $serverRows = array_map('intval', $each->fetchAll(\PDO::FETCH_COLUMN));

        // What each statement said and cost on the app's side: the same request, sent once more with a tap.
        [$tapExit, $tapOut, $tapErr] = self::php(__DIR__ . '/Support/tap_statements.php', [(string) self::SCALE, $method, $uri, $method === 'GET' ? '0' : '1']);
        $tap = $tapExit === 0 ? json_decode(trim($tapOut), true) : null;
        if (!is_array($tap) || !isset($tap['statements'])) {
            $row['verdict'] = 'the statement tap failed: ' . substr($tapErr, 0, 200);

            return $row;
        }
        $statementsOfTap = $tap['statements'];
        if (count($statementsOfTap) !== count($serverRows)) {
            $row['verdict'] = sprintf('the tap saw %d statements and the server ran %d in the window: they cannot be paired', count($statementsOfTap), count($serverRows));

            return $row;
        }
        $tapReads = array_sum(array_column($statementsOfTap, 'read'));

        // Left out of both sides: the statements of the three types (by the rule, never by the route).
        $row['excluded'] = array_fill_keys(ExcludedStatementTypes::ALL, 0);
        $leftApp = $row['examined'] - $tapReads; // what the statements do not account for stays on the app's side
        $leftServer = $sum;
        $leftStatements = 0;
        $scanEnd = 0;
        $scanEndRows = 0;
        $scanOverCap = 0;
        foreach ($statementsOfTap as $i => $statement) {
            $type = ExcludedStatementTypes::of((string) $statement['sql'], $statement['plan']);
            if ($type !== null) {
                $row['excluded'][$type]++;
                $leftServer -= $serverRows[$i];

                continue;
            }
            // The fourth type is not left out: the scan's end read is taken off the app's count, the statement stays compared.
            $surplus = ExcludedStatementTypes::scanEndSurplus((string) $statement['sql'], $statement['plan'], (int) $statement['read'], $serverRows[$i]);
            if ($surplus > 0) {
                $scanEnd++;
                $scanEndRows += $surplus;
            }
            if (ExcludedStatementTypes::scanEndOverCap((string) $statement['sql'], $statement['plan'], (int) $statement['read'], $serverRows[$i])) {
                $scanOverCap++;
            }
            $leftApp += (int) $statement['read'] - $surplus;
            $leftStatements++;
        }
        $row['scan_end'] = $scanEnd;
        $row['scan_end_rows'] = $scanEndRows;
        $row['scan_over_cap'] = $scanOverCap;
        $row['unaccounted'] = $row['examined'] - $tapReads;
        $row['left_statements'] = $leftStatements;
        $row['left_examined'] = $leftApp;
        $row['left_server'] = $leftServer;
        $row['statements'] = $statements;
        $row['server'] = $sum;

        $row['diff'] = $leftServer === 0 ? ($leftApp === 0 ? 0.0 : INF) : abs($leftApp - $leftServer) / $leftServer;
        $row['verdict'] = RowsExaminedVerdict::of($scanOverCap, (int) $row['unaccounted'], $row['diff'], self::LIMIT);

        return $row;
    }

    /** @param list<array<string, mixed>> $table */
    private static function render(array $table): string
    {
        $lines = [sprintf('%-34s %-8s %7s %9s %9s  %-18s %-9s %6s %9s %9s %8s %s', 'route', 'class', 'queries', 'examined', 'server', 'left out (c/r/u)', 'scan end', 'left', 'left app', 'left srv', 'diff', 'verdict')];
        foreach ($table as $r) {
            $lines[] = sprintf(
                '%-34s %-8s %7s %9s %9s  %-18s %-9s %6s %9s %9s %8s %s',
                $r['route'],
                (string) $r['class'],
                (string) $r['queries'],
                (string) $r['examined'],
                (string) $r['server'],
                $r['excluded'] === [] ? '-' : implode('/', array_map(static fn (string $t): string => (string) $r['excluded'][$t], ExcludedStatementTypes::ALL)),
                sprintf('%d/-%d/%d%s', $r['scan_end'], $r['scan_end_rows'], $r['scan_over_cap'], $r['scan_over_cap'] > 0 ? '!' : ''),
                (string) $r['left_statements'],
                (string) $r['left_examined'],
                (string) $r['left_server'],
                $r['diff'] === null ? '-' : (is_infinite($r['diff']) ? 'inf' : round($r['diff'] * 100, 1) . '%'),
                $r['verdict'],
            );
        }
        $lines[] = '';
        $lines[] = 'left out: c = count_all, r = range_end, u = unique_miss (statements); "left" = statements still compared';
        $lines[] = 'scan end: statements adjusted / rows taken off / statements over the cap of 3 (not taken off; "!" marks a route that has one); the adjusted statements stay compared';

        return implode("\n", $lines);
    }

    /** @param list<array<string, mixed>> $table */
    private function writeEvidence(array $table): void
    {
        $dir = (string) (getenv('PEAK_MEASURE_OUT_DIR') ?: '');
        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $rows = array_map(static function (array $r): array {
            if (is_float($r['diff']) && is_infinite($r['diff'])) {
                $r['diff'] = 'inf';
            }

            return $r;
        }, $table);
        file_put_contents($dir . '/rows-examined-check.json', json_encode(['scale' => self::SCALE, 'sizes' => self::SIZES, 'limit' => self::LIMIT, 'routes' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/rows-examined-check.txt', self::render($table) . "\n");
    }
}
