<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabScenarios;

use PeakSim\Runtime\Simulate\Calibrate\CalibrationGate;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationPoint;
use PHPUnit\Framework\TestCase;

/**
 * The gate in front of a calibration measurement (spec 7.4.1): the degree to which a set of planned runs can tell c_q and c_row apart, for the
 * whole set and for every set that leaves one run out, and `poc/lab/scenarios/calibration-gate` run for real on the committed plans.
 */
final class CalibrationGateTest extends TestCase
{
    private const LAB_DATA = __DIR__ . '/../../../../../docs/research/lab-data';

    private static function point(string $id, float $q, float $r): CalibrationPoint
    {
        return new CalibrationPoint($id, $q, $r, 0.0, 2.0);
    }

    /**
     * An independent way to the same number: the smallest of |X v| over unit vectors v (that is the smallest singular value), scanned over
     * the angle, with the columns of X scaled to length 1. The gate itself does not scan; it uses the closed form for two columns.
     *
     * @param list<array{float, float}> $rows
     */
    private static function smallestSingularValueByScan(array $rows): float
    {
        $nq = sqrt(array_sum(array_map(static fn (array $r): float => $r[0] ** 2, $rows)));
        $nr = sqrt(array_sum(array_map(static fn (array $r): float => $r[1] ** 2, $rows)));
        $best = INF;
        $steps = 200000;
        for ($k = 0; $k <= $steps; ++$k) {
            $theta = M_PI * $k / $steps;
            $c = cos($theta);
            $s = sin($theta);
            $sum = 0.0;
            foreach ($rows as [$q, $r]) {
                $sum += ($c * $q / $nq + $s * $r / $nr) ** 2;
            }
            $best = min($best, sqrt($sum));
        }

        return $best;
    }

    public function testDegreeIsTheSmallestSingularValueOfTheColumnScaledDesignMatrix(): void
    {
        $sets = [
            [[1.0, 0.0], [0.0, 1.0]],
            [[1.0, 2.0], [2.0, 1.0], [3.0, 3.5]],
            [[1512.0, 26072.0], [2414.0, 41614.0], [608.0, 10529.0], [1848.0, 31428.0]],
            [[100.0, 5.0], [10.0, 500.0], [50.0, 50.0], [70.0, 20.0]],
        ];
        foreach ($sets as $rows) {
            $points = [];
            foreach ($rows as $i => [$q, $r]) {
                $points[] = self::point('p' . $i, $q, $r);
            }
            self::assertEqualsWithDelta(self::smallestSingularValueByScan($rows), CalibrationGate::degree($points), 1e-8, json_encode($rows));
        }
    }

    public function testOrthogonalRunsAreDegreeOneAndProportionalRunsAreDegreeZero(): void
    {
        self::assertEqualsWithDelta(1.0, CalibrationGate::degree([self::point('a', 3.0, 0.0), self::point('b', 0.0, 7.0)]), 1e-12);
        self::assertEqualsWithDelta(0.0, CalibrationGate::degree([self::point('a', 1.0, 17.0), self::point('b', 5.0, 85.0), self::point('c', 2.0, 34.0)]), 1e-7);
        // the rates scale with the rps of a run, not the degree of a set whose runs all have the same rows per query
        self::assertEqualsWithDelta(0.0, CalibrationGate::degree([self::point('a', 10.0, 170.0), self::point('b', 1.0, 17.0)]), 1e-7);
    }

    public function testASetThatCannotTellTheCoefficientsApartHasDegreeZero(): void
    {
        self::assertSame(0.0, CalibrationGate::degree([]));
        self::assertSame(0.0, CalibrationGate::degree([self::point('only', 4.0, 9.0)]));
        self::assertSame(0.0, CalibrationGate::degree([self::point('a', 0.0, 2.0), self::point('b', 0.0, 3.0)]), 'a column of zeros');
        self::assertSame(0.0, CalibrationGate::degree([self::point('a', 2.0, 0.0), self::point('b', 3.0, 0.0)]));
    }

    public function testASetWithNoBiasPasses(): void
    {
        $result = CalibrationGate::evaluate([
            self::point('cal-1', 10.0, 20.0),
            self::point('cal-2', 4.0, 60.0),
            self::point('cal-3', 40.0, 10.0),
            self::point('cal-4', 12.0, 30.0),
        ]);

        self::assertTrue($result['passed']);
        self::assertGreaterThanOrEqual(CalibrationGate::MIN_DEGREE, $result['min_degree']);
        self::assertCount(5, $result['sets'], 'the whole set and the four that leave one run out');
        self::assertNull($result['sets'][0]['left_out']);
        self::assertSame(['cal-1', 'cal-2', 'cal-3', 'cal-4'], $result['sets'][0]['ids']);
        self::assertSame(['cal-2', 'cal-3', 'cal-4'], $result['sets'][1]['ids']);
        self::assertSame('cal-1', $result['sets'][1]['left_out']);
    }

    public function testASetWhoseOnlyBadMemberIsOneLeaveOneOutSetIsRefused(): void
    {
        // Two runs are almost proportional (1:1 and 1:1.01); the third has a very different weight. The whole set tells the coefficients apart, and so
        // does every set but the one that leaves the third run out, where only the two near-proportional runs remain.
        $result = CalibrationGate::evaluate([
            self::point('a', 1.0, 1.0),
            self::point('b', 10.0, 0.0),
            self::point('c', 1.0, 1.01),
        ]);

        self::assertTrue($result['sets'][0]['passed'], 'the whole set is fine');
        self::assertSame(['b'], array_values(array_map(static fn (array $s): string => (string) $s['left_out'], array_filter($result['sets'], static fn (array $s): bool => !$s['passed']))));
        self::assertFalse($result['passed']);
        self::assertSame('b', $result['min_left_out']);
        self::assertLessThan(0.01, $result['min_degree']);
    }

    public function testTheThresholdIsInclusiveAndIsTheOneTheSpecGives(): void
    {
        self::assertSame(0.5, CalibrationGate::MIN_DEGREE);
        $points = [self::point('a', 1.0, 0.0), self::point('b', 1.0, 1.0)];
        $degree = CalibrationGate::degree($points);
        // (a set of two has no leave-one-out set that can pass, so only the whole set is looked at here)
        self::assertTrue(CalibrationGate::evaluate($points, $degree)['sets'][0]['passed'], 'a degree equal to the threshold passes');
        self::assertFalse(CalibrationGate::evaluate($points, $degree + 1e-9)['sets'][0]['passed']);
        self::assertTrue(CalibrationGate::evaluate($points, 0.0)['passed'], 'a threshold of 0 lets everything through');
    }

    public function testRunsNeedDistinctIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CalibrationGate::evaluate([self::point('a', 1.0, 2.0), self::point('a', 2.0, 1.0)]);
    }

    /** The rates the gate sees are the rates the fit sees: one function makes both. */
    public function testRatesAreThoseOfFromRoutes(): void
    {
        $routes = [
            ['rps' => 0.5, 'queries' => 8.0, 'rows' => 223.25],
            ['rps' => 0.25, 'queries' => 3.0, 'rows' => null],
            ['rps' => 0.125, 'queries' => 12.0, 'rows' => 4000.0],
        ];
        [$q, $r, $unknown] = CalibrationPoint::rates($routes);
        $point = CalibrationPoint::fromRoutes('x', 2.0, 0.3, $routes);

        self::assertSame($point->queryRate, $q);
        self::assertSame($point->rowRate, $r);
        self::assertSame($point->unknownCostRoutes, $unknown);
        self::assertSame(1, $unknown);
        self::assertEqualsWithDelta(0.5 * 8.0 + 0.125 * 12.0, $q, 1e-12);
    }

    /** @return array{int, string, string} */
    private static function gateCli(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, __DIR__ . '/../../../../lab/scenarios/calibration-gate.php'], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($p), $out, $err];
    }

    /** @return list<string> */
    private static function r3Args(): array
    {
        $args = ['--estimate=' . self::LAB_DATA . '/estimate-r3/rule/100k', '--run-scale=cal-4:3000', '--run-scale=cal-5:30000'];
        foreach (range(1, 5) as $n) {
            $args[] = sprintf('--run=cal-%d:%s/calibration-limits-off/cal-%d/plan.json', $n, self::LAB_DATA, $n);
        }

        return $args;
    }

    public function testTheFiveRunsOfTheRemeasuredCalibrationAreRefused(): void
    {
        $out = sys_get_temp_dir() . '/cal-gate-' . bin2hex(random_bytes(4)) . '/calibration-gate.json';
        [$code, $stdout, $stderr] = self::gateCli([...self::r3Args(), '--out=' . $out]);

        self::assertSame(14, $code, $stderr);
        self::assertStringContainsString('CALIBRATION GATE REFUSED', $stderr);
        $record = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        unlink($out);
        rmdir(dirname($out));

        self::assertFalse($record['passed']);
        $byLeftOut = [];
        foreach ($record['sets'] as $set) {
            $byLeftOut[$set['left_out'] ?? 'all'] = $set['degree'];
            self::assertFalse($set['passed'], 'every one of the six sets is below the threshold');
        }
        // the degree of the whole set is about 0.0059 (every run has 17.0 to 17.5 rows per query); the smallest is the set without cal-4
        self::assertEqualsWithDelta(0.00594, $byLeftOut['all'], 5e-5);
        self::assertEqualsWithDelta(0.00383, $byLeftOut['cal-4'], 5e-5);
        self::assertSame('cal-4', $record['min_left_out']);
        self::assertStringContainsString('rows per query 17.2425', $stdout);
    }

    /**
     * The two earlier sets that could not tell the coefficients apart, from the first calibration's plans and its estimate (the estimate that
     * calibration used): the three runs of the first fit and the four runs with cal-4 at 3,000 rows.
     */
    public function testTheEarlierDefectiveSetsAreRefused(): void
    {
        $cases = [
            'three runs (cal-1..cal-3)' => [['cal-1', 'cal-2', 'cal-3'], [], 0.01878, 'cal-2'],
            'four runs (cal-1..cal-4, cal-4 at 3,000 rows)' => [['cal-1', 'cal-2', 'cal-3', 'cal-4'], ['--run-scale=cal-4:3000'], 0.08567, 'cal-4'],
        ];
        foreach ($cases as $label => [$runs, $extra, $allDegree, $minLeftOut]) {
            $args = ['--estimate=' . self::LAB_DATA . '/estimate', ...$extra];
            foreach ($runs as $id) {
                $args[] = sprintf('--run=%s:%s/calibration/%s/plan.json', $id, self::LAB_DATA, $id);
            }
            $out = sys_get_temp_dir() . '/cal-gate-' . bin2hex(random_bytes(4)) . '/calibration-gate.json';
            [$code, , $stderr] = self::gateCli([...$args, '--out=' . $out]);
            $record = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
            unlink($out);
            rmdir(dirname($out));

            self::assertSame(14, $code, $label . ': ' . $stderr);
            self::assertFalse($record['passed'], $label);
            self::assertEqualsWithDelta($allDegree, $record['sets'][0]['degree'], 5e-5, $label);
            self::assertLessThan(0.1, $record['min_degree'], $label);
            self::assertSame($minLeftOut, $record['min_left_out'], $label);
        }
    }

    public function testTheGateRecordCarriesTheInputs(): void
    {
        $out = sys_get_temp_dir() . '/cal-gate-' . bin2hex(random_bytes(4)) . '/calibration-gate.json';
        self::gateCli([...self::r3Args(), '--out=' . $out]);
        $record = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        unlink($out);
        rmdir(dirname($out));

        self::assertSame(['cal-1', 'cal-2', 'cal-3', 'cal-4', 'cal-5'], array_column($record['inputs'], 'id'));
        self::assertSame(hash_file('sha256', self::LAB_DATA . '/calibration-limits-off/cal-1/plan.json'), $record['inputs'][0]['plan_sha256']);
        self::assertSame(['entities' => 3000, 'images' => 3000, 'references' => 3000], $record['inputs'][3]['tables']);
        self::assertSame(0.5, $record['threshold']);
    }

    public function testAnUnreadablePlanAndAnUnknownRouteAreNotGateAnswers(): void
    {
        [$code, , $stderr] = self::gateCli(['--estimate=' . self::LAB_DATA . '/estimate-r3/rule/100k', '--run=cal-1:/no/such/plan.json']);
        self::assertSame(2, $code);
        self::assertStringContainsString('cannot read the plan', $stderr);

        $dir = sys_get_temp_dir() . '/cal-gate-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/plan.json', json_encode(['rps' => 1.0, 'routes' => [['route' => 'GET /not/a/route', 'rps' => 1.0]]]));
        [$code, , $stderr] = self::gateCli(['--estimate=' . self::LAB_DATA . '/estimate-r3/rule/100k', '--run=cal-1:' . $dir . '/plan.json']);
        unlink($dir . '/plan.json');
        rmdir($dir);
        self::assertSame(22, $code);
        self::assertStringContainsString('does not have', $stderr);
    }

    /** The fit is untouched by the gate: the committed fit of the five runs is reproduced byte for byte by the same tool. */
    public function testTheFitOutputIsByteIdenticalToTheCommittedFit(): void
    {
        $dir = sys_get_temp_dir() . '/cal-fit-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $cmd = [PHP_BINARY, __DIR__ . '/../../../bin/inner/simulate.php', '--estimate=' . self::LAB_DATA . '/estimate-r3/rule/100k',
            '--scenario=' . self::LAB_DATA . '/calibration-limits-off/fit-5runs/calibration.yaml', '--out=' . $dir];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $code = proc_close($p);

        self::assertSame(0, $code, $err);
        foreach (['prediction.json', 'loaded.json'] as $file) {
            self::assertSame(
                file_get_contents(self::LAB_DATA . '/calibration-limits-off/fit-5runs/out/' . $file),
                file_get_contents($dir . '/' . $file),
                $file . ' is not the committed one, byte for byte',
            );
            unlink($dir . '/' . $file);
        }
        foreach (glob($dir . '/*') ?: [] as $left) {
            unlink($left);
        }
        rmdir($dir);
    }
}
