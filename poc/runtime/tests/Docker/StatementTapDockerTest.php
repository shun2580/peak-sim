<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PeakSim\Runtime\Tests\Docker\Support\DockerCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `bin/measure-one --tap-out=<file>`: what each statement of the cold attempt read, and its plan, written to a file.
 * The verification app (small scale) stands for the target; every route of it that needs no parameter is sent.
 *
 * What is checked, against the real server:
 *  1. Without the flag nothing is written and the output has the shape it has always had; with the flag the lines on
 *     standard output are the same as without it.
 *  2. The tap does not disturb the figure: the cold `rows_examined` of a tapped run equals the untapped one (the untapped
 *     one is first shown to be stable, so a difference is the tap's).
 *  3. What the statements read adds up to the figure (nothing left unexplained), and the counts add up to the query count.
 *  4. The plan of a write (insert, update, delete) can be taken on this stack's server, and a plan has the columns the
 *     readers of the file need.
 * Only the cold window is tapped, and a tap file that cannot be written does not stop the measurement.
 */
#[Group('in-stack')]
final class StatementTapDockerTest extends DockerCase
{
    private const SCALE = 100; // one of the configured scales (100, 1000, 10000)

    private const SIZES = [30, 30, 100, 3];

    /** The fields of an attempt that must be the same with and without the tap. Not peak_mem (the tap holds memory), not bytes. */
    private const SAME = ['route', 'method', 'scale', 'axis', 'attempt', 'status', 'queries', 'by_connection', 'tables', 'gate_bypass', 'class', 'detail', 'rows_read', 'rows_examined', 'rows_examined_baseline'];

    /** @var list<array{method: string, uri: string}> */
    private static array $routes = [];

    /** @var list<string> */
    private static array $files = [];

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
        foreach (self::$files as $file) {
            @unlink($file);
        }
        self::drop(self::SCALE);
    }

    private static function tmp(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tap');
        self::assertIsString($path);
        self::$files[] = $path;

        return $path;
    }

    /**
     * @param list<string> $extra
     *
     * @return array{int, list<array<string, mixed>>, string}
     */
    private static function cold(string $method, string $uri, array $extra = []): array
    {
        $args = ['--scale=' . self::SCALE, '--uri=' . $uri, '--method=' . $method, '--phases=cold'];
        if ($method !== 'GET') {
            $args[] = '--csrf';
        }

        return self::measure([...$args, ...$extra]);
    }

    /** @return array<string, mixed> */
    private static function read(string $file): array
    {
        $doc = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($doc);

        return $doc;
    }

    public function testTheTapDoesNotChangeWhatIsMeasuredAndWhatItRecordsAddsUpToTheFigure(): void
    {
        self::assertGreaterThanOrEqual(10, count(self::$routes), 'the verification app has more than ten routes without a parameter');

        $problems = [];
        $dml = 0;
        $dmlExplained = 0;
        $selectsWithAPlan = 0;
        foreach (self::$routes as $route) {
            $label = $route['method'] . ' ' . $route['uri'];

            // 1+2. The same request, untapped twice and tapped once.
            [$exit1, $plain1] = self::cold($route['method'], $route['uri']);
            [$exit2, $plain2] = self::cold($route['method'], $route['uri']);
            $file = self::tmp();
            @unlink($file);
            [$exit3, $tapped, $err3] = self::cold($route['method'], $route['uri'], ['--tap-out=' . $file]);
            if ($exit1 !== 0 || $exit2 !== 0 || $exit3 !== 0 || count($plain1) !== 1 || count($plain2) !== 1 || count($tapped) !== 1) {
                $problems[] = sprintf('%s: exits %d/%d/%d, attempts %d/%d/%d: %s', $label, $exit1, $exit2, $exit3, count($plain1), count($plain2), count($tapped), substr($err3, 0, 200));

                continue;
            }
            $a = $plain1[0];
            $b = $plain2[0];
            $t = $tapped[0];
            if (($a['rows_examined'] ?? null) !== ($b['rows_examined'] ?? null)) {
                $problems[] = sprintf('%s: the figure is not stable without the tap (%s then %s), so the tap cannot be judged on it', $label, json_encode($a['rows_examined'] ?? null), json_encode($b['rows_examined'] ?? null));

                continue;
            }
            foreach (self::SAME as $key) {
                if (($a[$key] ?? null) !== ($t[$key] ?? null)) {
                    $problems[] = sprintf('%s: "%s" is %s without the tap and %s with it', $label, $key, json_encode($a[$key] ?? null), json_encode($t[$key] ?? null));
                }
            }
            self::assertSame(array_keys($a), array_keys($t), $label . ': the tap adds no field to the attempt');

            if (!is_file($file)) {
                $problems[] = $label . ': the tap file was not written';

                continue;
            }
            $doc = self::read($file);

            // 3. The statements add up.
            $window = $doc['window'];
            $statements = $doc['statements'];
            $sumRows = 0;
            $sumCount = 0;
            foreach ($statements as $s) {
                $sumRows += (int) $s['rows_sum'];
                $sumCount += (int) $s['count'];
            }
            $checks = [
                'tap_version' => [1, $doc['tap_version']],
                'phase' => ['cold', $doc['phase']],
                'counters_available' => [true, $window['counters_available']],
                'cold_rows_examined is the attempt\'s' => [$t['rows_examined'], $window['cold_rows_examined']],
                'baseline is the attempt\'s' => [$t['rows_examined_baseline'], $window['baseline']],
                'statement_events is the attempt\'s queries' => [$t['queries'], $window['statement_events']],
                'the counts add up to the events' => [$window['statement_events'], $sumCount],
                'the rows add up to rows_sum' => [$window['rows_sum'], $sumRows],
                'nothing is left unexplained' => [0, $window['unaccounted']],
                'no statement is unread' => [0, $window['unread_events']],
            ];
            foreach ($checks as $name => [$expected, $actual]) {
                if ($expected !== $actual) {
                    $problems[] = sprintf('%s: %s (expected %s, got %s)', $label, $name, json_encode($expected), json_encode($actual));
                }
            }

            // 4. The plans.
            foreach ($statements as $s) {
                if (in_array($s['type'], ['update', 'delete', 'insert'], true)) {
                    $dml++;
                    $both = true;
                    foreach (['explain_first', 'explain_last'] as $which) {
                        $plan = $s[$which];
                        if (!is_array($plan) || ($plan['ok'] ?? false) !== true || ($plan['rows'] ?? []) === [] || !isset($plan['rows'][0]['table'])) {
                            $both = false;
                            $problems[] = sprintf('%s: no plan for %s (%s): %s', $label, substr((string) $s['sql'], 0, 60), $which, json_encode($plan));
                        }
                    }
                    $dmlExplained += $both ? 1 : 0;
                }
                if ($s['type'] === 'select' && is_array($s['explain_first']) && ($s['explain_first']['ok'] ?? false) === true) {
                    foreach ($s['explain_first']['rows'] as $row) {
                        if (array_keys($row) !== ['id', 'select_type', 'table', 'partitions', 'type', 'possible_keys', 'key', 'key_len', 'ref', 'rows', 'filtered', 'extra']) {
                            $problems[] = $label . ': a plan row has other columns: ' . json_encode(array_keys($row));
                        }
                        if ($row['type'] !== null) {
                            $selectsWithAPlan++;
                        }
                    }
                } elseif ($s['type'] === 'select') {
                    $problems[] = sprintf('%s: no plan for %s: %s', $label, substr((string) $s['sql'], 0, 60), json_encode($s['explain_first']));
                }
            }
        }

        self::assertGreaterThan(0, $dml, 'the verification app has a route that writes (POST /ok/bulk inserts), so the tap saw a write');
        self::assertSame($dml, $dmlExplained, 'every write has a plan, for the first and for the last bindings');
        self::assertGreaterThan(0, $selectsWithAPlan, 'a select has a plan with an access type');
        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function testOnlyTheColdWindowIsTappedAndTheOtherPhasesAreLeftAlone(): void
    {
        $file = self::tmp();
        @unlink($file);
        [$exit, $attempts] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/eager', '--tap-out=' . $file]);

        self::assertSame(0, $exit);
        self::assertCount(3, $attempts, 'warm-up, cold and warm are all still sent');
        $cold = $attempts[1];
        self::assertSame(2, $cold['attempt']);
        $doc = self::read($file);
        self::assertSame('cold', $doc['phase']);
        self::assertSame($cold['queries'], $doc['window']['statement_events'], 'the statements of the cold window only');
        self::assertSame($cold['rows_examined'], $doc['window']['cold_rows_examined']);
    }

    public function testWithoutAColdAttemptNoTapFileIsMade(): void
    {
        $file = self::tmp();
        @unlink($file);

        [$exit, $attempts, $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/eager', '--phases=warmup,warm', '--tap-out=' . $file]);

        self::assertSame(0, $exit);
        self::assertCount(2, $attempts);
        self::assertFileDoesNotExist($file);
        self::assertStringContainsString('tap: no cold attempt', $err);
    }

    public function testATapFileThatCannotBeWrittenDoesNotStopTheMeasurement(): void
    {
        [$exit, $attempts, $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/eager', '--phases=cold', '--tap-out=/no-such-directory-for-the-tap/x.json']);

        self::assertSame(0, $exit);
        self::assertCount(1, $attempts);
        self::assertNotNull($attempts[0]['rows_examined']);
        self::assertStringContainsString('cannot write the tap file', $err);
    }

    public function testATapOutWithNoNameIsAUsageError(): void
    {
        [$exit, , $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/eager', '--tap-out']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('--tap-out needs a file name', $err);
    }
}
