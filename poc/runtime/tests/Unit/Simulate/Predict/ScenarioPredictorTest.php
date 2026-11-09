<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\LimitSearch;
use PeakSim\Runtime\Simulate\Predict\ScenarioPredictor;
use PHPUnit\Framework\TestCase;

/** Every expected number below is worked out by hand from the figures of PredictFixtures and the coefficients written in the test. */
final class ScenarioPredictorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function scenario(array $extraRoutes = []): array
    {
        return [
            'id' => 'peak-1',
            'kind' => 'target',
            'tables' => ['items' => 100000],
            'unknown_tables' => [],
            'zero_rps_routes' => ['GET /z'],
            'routes' => array_merge([
                PredictFixtures::route('GET /a', 10.0, 5.0, 1000.0),
                PredictFixtures::route('GET /b', 5.0, 2.0, 400.0, 4000.0),
            ], $extraRoutes),
        ];
    }

    public function testTheUtilisationLimitAndShareFollowFromTheFiguresByHand(): void
    {
        // coefficients 0.001 per query and 0.0001 per row; 2 cores; threshold 0.8
        // point:  /a 10 x (0.005 + 0.1) = 1.05, /b 5 x (0.002 + 0.04) = 0.21 -> L = 1.26 -> 0.63
        // upper:  /b reads 4000 rows: 5 x (0.002 + 0.4) = 2.01 -> L = 3.06 -> 1.53
        $p = ScenarioPredictor::predict(self::scenario(), PredictFixtures::twoCoefficient(0.001, 0.0001, 0.0005, 0.0002), 2.0, 0.8);
        $u = $p['utilization'];
        self::assertEqualsWithDelta(0.63, $u['point'], 1e-12);
        self::assertEqualsWithDelta(1.53, $u['upper'], 1e-12);
        self::assertSame(['point' => false, 'upper' => true], $u['over_threshold']);
        // the range of a two-coefficient calibration is not widened by the one-coefficient fits beside it (2026-11-09): with no width of the
        // calibration, only the readings of the estimate widen it: the point 0.63 and the upper side 1.53
        self::assertEqualsWithDelta(0.63, $u['range']['min'], 1e-12);
        self::assertEqualsWithDelta(1.53, $u['range']['max'], 1e-12);
        // limit, point: 0.63 x k/10 > 0.8 first at k = 13 (0.819; k = 12 gives 0.756) -> 1.3x
        self::assertSame(['status' => LimitSearch::FOUND, 'k' => 13, 'multiplier' => 1.3], $p['limit_multiplier']['point']);
        // limit, safe side: 1.53 x k/10 > 0.8 first at k = 6 (0.918; k = 5 gives 0.765) -> 0.6x, already over today
        self::assertSame(['status' => LimitSearch::ALREADY_OVER, 'k' => 6, 'multiplier' => 0.6], $p['limit_multiplier']['safe']);
        self::assertSame(['GET /a', 'GET /b'], array_column($p['dominant_routes'], 'route'));
        self::assertEqualsWithDelta(1.05 / 1.26, $p['dominant_routes'][0]['share'], 1e-12);
        self::assertSame(['GET /z'], $p['zero_rps_routes']);
        self::assertFalse($p['low_coverage']);
        self::assertSame([], $p['warnings']);
    }

    public function testAOneScenarioCalibrationGivesTheMiddleOfItsTwoPredictionsAndBothBoundTheRange(): void
    {
        // queries only 0.002: 0.002 x 60 = 0.12;  rows only 0.0004: 0.0004 x 12000 = 4.8;  the middle 2.46 -> 1.23 on 2 cores
        $p = ScenarioPredictor::predict(self::scenario(), PredictFixtures::oneCoefficient(0.002, 0.0004), 2.0, 0.8);
        self::assertEqualsWithDelta(1.23, $p['utilization']['point'], 1e-12);
        self::assertEqualsWithDelta(0.06, $p['utilization']['range']['min'], 1e-12);
        // the upper rows 30,000/s: 0.0004 x 30000 = 12 -> 6.0 on 2 cores
        self::assertEqualsWithDelta(6.0, $p['utilization']['range']['max'], 1e-12);
    }

    public function testLowCoverageIsReportedAndTheRouteWithNoCostAddsNothing(): void
    {
        // 7 of 22 rps (31.8%) have no cost
        $p = ScenarioPredictor::predict(self::scenario([PredictFixtures::unknown('GET /u', 7.0)]), PredictFixtures::twoCoefficient(0.001, 0.0001), 2.0, 0.8);
        self::assertTrue($p['low_coverage']);
        self::assertEqualsWithDelta(0.63, $p['utilization']['point'], 1e-12);
        self::assertStringContainsString('low_coverage', $p['warnings'][0]);
        self::assertSame('GET /u', $p['coverage']['unknown_cost_routes'][0]['route']);
        self::assertSame(1, $p['coverage']['by_source']['unknown']['routes']);
    }

    public function testTheMarksOfTheCountsAreCarriedPerRoute(): void
    {
        $s = self::scenario([PredictFixtures::route('GET /m', 1.0, 1.0, 1.0, null, ['n_mark' => 'measured'])]);
        $p = ScenarioPredictor::predict($s, PredictFixtures::twoCoefficient(0.001, 0.0001), 2.0, 0.8);
        self::assertSame(['measured' => 1, 'interpolated' => 0, 'extrapolated' => 2, 'unknown' => 0], $p['n_marks']);
    }

    public function testTheSameInputGivesTheSameOutputByteForByte(): void
    {
        $evaluate = static fn (array $tables, array $rps): array => self::scenario()['routes'];
        $a = ScenarioPredictor::predict(self::scenario(), PredictFixtures::twoCoefficient(0.001, 0.0001, 0.0005, 0.0002), 2.0, 0.8, $evaluate);
        $b = ScenarioPredictor::predict(self::scenario(), PredictFixtures::twoCoefficient(0.001, 0.0001, 0.0005, 0.0002), 2.0, 0.8, $evaluate);
        self::assertSame(json_encode($a, JSON_PRESERVE_ZERO_FRACTION), json_encode($b, JSON_PRESERVE_ZERO_FRACTION));
    }

    public function testNoCoresIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScenarioPredictor::predict(self::scenario(), PredictFixtures::twoCoefficient(0.001, 0.0001), 0.0, 0.8);
    }
}
