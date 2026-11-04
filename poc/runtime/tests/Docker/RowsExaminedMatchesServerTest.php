<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PeakSim\Runtime\Tests\Docker\Support\DockerCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Checks the "rows examined" figure of a request (the growth of the session's Handler_read_* counters, see
 * `Run/RowsExamined`) against what the database server itself counted for the same statements:
 * `performance_schema.events_statements_history_long`, column ROWS_EXAMINED, summed over the statements of the same
 * thread that ran between the two snapshots (the server keeps no text for prepared statements, so the window is found by
 * its place between the transaction's START TRANSACTION and ROLLBACK).
 *
 * The server's side is read from a SEPARATE connection of this test, never from the app's connection. The two numbers
 * are counted by different mechanisms (storage-engine handler calls against the optimizer's per-statement count) and
 * need not be equal; the figure is accepted when every route is within 10% of the server's count. The limit is not
 * moved to make a route pass: a route over it is a finding, and which number to trust is for the owner to decide.
 *
 * It runs `bin/measure-one` for the cold attempt of every route of the verification app (so the real wiring is what is
 * checked), then finds that process's thread and the window between its two snapshots in the server's history.
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
     * @return array{route: string, class: ?string, queries: ?int, returned: ?int, examined: ?int, baseline: ?int, server: ?int, statements: ?int, diff: ?float, verdict: string}
     */
    private function compare(string $method, string $uri): array
    {
        $row = ['route' => $method . ' ' . $uri, 'class' => null, 'queries' => null, 'returned' => null, 'examined' => null, 'baseline' => null, 'server' => null, 'statements' => null, 'diff' => null, 'verdict' => ''];

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
        $row['statements'] = $statements;
        $row['server'] = $sum;

        $row['diff'] = $sum === 0 ? ($row['examined'] === 0 ? 0.0 : INF) : abs($row['examined'] - $sum) / $sum;
        $row['verdict'] = $row['diff'] > self::LIMIT ? sprintf('differs by %s', is_infinite($row['diff']) ? 'an unbounded share (server counted 0)' : round($row['diff'] * 100, 1) . '%') : 'ok';

        return $row;
    }

    /** @param list<array<string, mixed>> $table */
    private static function render(array $table): string
    {
        $lines = [sprintf('%-34s %-8s %7s %9s %9s %9s %8s %s', 'route', 'class', 'queries', 'returned', 'examined', 'server', 'diff', 'verdict')];
        foreach ($table as $r) {
            $lines[] = sprintf(
                '%-34s %-8s %7s %9s %9s %9s %8s %s',
                $r['route'],
                (string) $r['class'],
                (string) $r['queries'],
                (string) $r['returned'],
                (string) $r['examined'],
                (string) $r['server'],
                $r['diff'] === null ? '-' : (is_infinite($r['diff']) ? 'inf' : round($r['diff'] * 100, 1) . '%'),
                $r['verdict'],
            );
        }

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
