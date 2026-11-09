<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Calibrate\CalibrationPoint;
use PeakSim\Runtime\Simulate\Calibrate\Calibrator;
use PeakSim\Runtime\Simulate\Predict\CalibrationWidth;
use PeakSim\Runtime\Simulate\Predict\ScenarioPredictor;
use PHPUnit\Framework\TestCase;

/**
 * The width a prediction gets from the calibration (simulator-spec.md section 6.3, 2026-11-09), with one, two and three calibration scenarios.
 * One core, so a load is a utilisation. Every expected number is worked out by hand from the three points below.
 *
 *   A  queries 10/s  rows 100/s   observed 0.20
 *   B  queries 20/s  rows 100/s   observed 0.30
 *   C  queries 10/s  rows 300/s   observed 0.50
 *
 * The pairs fit exactly: A+B c_q 0.01 c_row 0.001; A+C c_q 0.005 c_row 0.0015; B+C c_q 0.008 c_row 0.0014.
 * All three: Saa 600, Sbb 110000, Sab 6000, Say 13, Sby 200, det 3e7 -> c_q 0.23e6/3e7 = 0.0076667, c_row 42000/3e7 = 0.0014.
 *
 * The target is one route at 1 rps with 100 queries and 2000 rows, 3000 rows on the upper side. Its readings of the estimate:
 * point (100, 2000), upper (100, 3000), low (100, 2000), high (100, 3000).
 */
final class CalibrationWidthTest extends TestCase
{
    private static function point(string $id, float $q, float $r, float $observed): CalibrationPoint
    {
        return new CalibrationPoint($id, $q, $r, $observed, 1.0);
    }

    /** @return array<string, mixed> */
    private static function target(): array
    {
        return [
            'id' => 't',
            'kind' => 'target',
            'tables' => ['items' => 1000],
            'unknown_tables' => [],
            'zero_rps_routes' => [],
            'routes' => [PredictFixtures::route('GET /x', 1.0, 100.0, 2000.0, 3000.0)],
        ];
    }

    /** @param list<CalibrationPoint> $points @return array<string, mixed> */
    private static function utilization(array $points): array
    {
        $calibration = (new Calibrator())->calibrate($points);
        $width = CalibrationWidth::of($points, $calibration);

        return ScenarioPredictor::predict(self::target(), $calibration, 1.0, 0.8, null, $width)['utilization'];
    }

    public function testThreeScenariosGiveTheRangeOfTheFitsWithOneLeftOutAndOnlyThose(): void
    {
        $u = self::utilization([self::point('A', 10, 100, 0.20), self::point('B', 20, 100, 0.30), self::point('C', 10, 300, 0.50)]);
        // point: 100 x 0.0076667 + 2000 x 0.0014 = 3.56667; the upper side 0.76667 + 4.2 = 4.96667
        self::assertEqualsWithDelta(3.5666667, $u['point'], 1e-6);
        self::assertEqualsWithDelta(4.9666667, $u['upper'], 1e-6);
        // the fits without C, B, A on the point reading: 1 + 2 = 3.0, 0.5 + 3 = 3.5, 0.8 + 2.8 = 3.6; on the upper side: 4.0, 5.0, 5.0
        self::assertEqualsWithDelta(3.0, $u['range']['min'], 1e-9);
        self::assertEqualsWithDelta(5.0, $u['range']['max'], 1e-9);
        self::assertSame('leave_one_out', $u['range_basis']['calibration_width']);
        self::assertSame(['all', 'without:A', 'without:B', 'without:C'], $u['range_basis']['calibration_fits']);
        // the ratios: 5.0 / 3.56667 and 3.56667 / 3.0
        self::assertEqualsWithDelta(5.0 / 3.5666667, $u['width']['upper_over_point'], 1e-6);
        self::assertEqualsWithDelta(3.5666667 / 3.0, $u['width']['point_over_lower'], 1e-6);
        // the estimate alone (point calibration): 3.56667 to 4.96667; the calibration alone (point reading): 3.0 to 3.6
        self::assertEqualsWithDelta(3.5666667, $u['width']['estimate_only']['min'], 1e-6);
        self::assertEqualsWithDelta(4.9666667, $u['width']['estimate_only']['max'], 1e-6);
        self::assertEqualsWithDelta(3.0, $u['width']['calibration_only']['min'], 1e-9);
        self::assertEqualsWithDelta(3.6, $u['width']['calibration_only']['max'], 1e-9);
        self::assertNull($u['width']['calibration_width_note']);
    }

    public function testTheOneCoefficientFitsAreNotMixedIntoAThreeScenarioRange(): void
    {
        $points = [self::point('A', 10, 100, 0.20), self::point('B', 20, 100, 0.30), self::point('C', 10, 300, 0.50)];
        $calibration = (new Calibrator())->calibrate($points);
        // the calibration does hold the one-coefficient fits ...
        self::assertContains('queries_only', array_map(static fn ($f): string => $f->kind, $calibration->fits));
        // ... and the width does not use them
        self::assertNotContains('queries_only', CalibrationWidth::of($points, $calibration)->labels());
        self::assertNotContains('rows_only', CalibrationWidth::of($points, $calibration)->labels());
    }

    public function testTwoScenariosSayThatTheWidthOfTheCalibrationCannotBeGiven(): void
    {
        $u = self::utilization([self::point('A', 10, 100, 0.20), self::point('B', 20, 100, 0.30)]);
        // the pair fits exactly: c_q 0.01, c_row 0.001 -> 1 + 2 = 3.0, upper side 1 + 3 = 4.0
        self::assertEqualsWithDelta(3.0, $u['point'], 1e-9);
        self::assertEqualsWithDelta(3.0, $u['range']['min'], 1e-9);
        self::assertEqualsWithDelta(4.0, $u['range']['max'], 1e-9);
        self::assertSame('unavailable', $u['range_basis']['calibration_width']);
        self::assertSame(['two_coefficient'], $u['range_basis']['calibration_fits']);
        self::assertNull($u['width']['calibration_only']);
        self::assertStringContainsString('cannot be given', (string) $u['width']['calibration_width_note']);
        // what is left is the estimate's width alone: 3.0 to 4.0
        self::assertEqualsWithDelta(3.0, $u['width']['estimate_only']['min'], 1e-9);
        self::assertEqualsWithDelta(4.0, $u['width']['estimate_only']['max'], 1e-9);
        self::assertEqualsWithDelta(4.0 / 3.0, $u['width']['upper_over_point'], 1e-9);
        self::assertEqualsWithDelta(1.0, $u['width']['point_over_lower'], 1e-9);
    }

    public function testOneScenarioGivesTheTwoOneCoefficientFits(): void
    {
        $u = self::utilization([self::point('A', 10, 100, 0.20)]);
        // queries only c = 10 x 0.2 / 100 = 0.02; rows only c = 100 x 0.2 / 10000 = 0.002
        // point reading: 0.02 x 100 = 2.0 and 0.002 x 2000 = 4.0, the point is their middle 3.0; upper side: 2.0 and 0.002 x 3000 = 6.0
        self::assertEqualsWithDelta(3.0, $u['point'], 1e-9);
        self::assertEqualsWithDelta(2.0, $u['range']['min'], 1e-9);
        self::assertEqualsWithDelta(6.0, $u['range']['max'], 1e-9);
        self::assertSame('one_coefficient_pair', $u['range_basis']['calibration_width']);
        self::assertEqualsWithDelta(2.0, $u['width']['calibration_only']['min'], 1e-9);
        self::assertEqualsWithDelta(4.0, $u['width']['calibration_only']['max'], 1e-9);
        self::assertEqualsWithDelta(2.0, $u['width']['upper_over_point'], 1e-9);
        self::assertEqualsWithDelta(1.5, $u['width']['point_over_lower'], 1e-9);
    }

    public function testTheRatiosAreUndefinedNotInfiniteWhenTheLowerEndIsZero(): void
    {
        // queries only c = 0 everywhere: a route with no queries and rows only
        $points = [self::point('A', 0, 100, 0.0), self::point('B', 0, 200, 0.0), self::point('C', 0, 300, 0.0)];
        $calibration = (new Calibrator())->calibrate($points);
        $u = ScenarioPredictor::predict(self::target(), $calibration, 1.0, 0.8, null, CalibrationWidth::of($points, $calibration))['utilization'];
        self::assertSame(0.0, $u['point']);
        self::assertNull($u['width']['upper_over_point']);
        self::assertNull($u['width']['point_over_lower']);
    }

    public function testTheSameInputGivesTheSameWidthByteForByte(): void
    {
        $points = [self::point('A', 10, 100, 0.20), self::point('B', 20, 100, 0.30), self::point('C', 10, 300, 0.50)];
        $a = json_encode(self::utilization($points), JSON_PRESERVE_ZERO_FRACTION);
        $b = json_encode(self::utilization($points), JSON_PRESERVE_ZERO_FRACTION);
        self::assertSame($a, $b);
    }
}
