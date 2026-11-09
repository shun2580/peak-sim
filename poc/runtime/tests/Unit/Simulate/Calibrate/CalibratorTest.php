<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Calibrate;

use InvalidArgumentException;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationFit;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationPoint;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationResult;
use PeakSim\Runtime\Simulate\Calibrate\Calibrator;
use PHPUnit\Framework\TestCase;

/**
 * The expected values here are the numbers the data were MADE from. Each scenario's routes (rps, queries per request,
 * rows per request) and the coefficients are fixed first, here in the test; the observed utilisation is computed from them
 * by the formula L = sum(rps * (c_q * queries + c_row * rows)), divided by the cores; the calibration is asked for the
 * coefficients back. Nothing below is read off a run of Calibrator.
 *
 * Floating-point comparisons allow a relative error of RELATIVE (the data are exact to about 1e-15; the allowance leaves
 * room for the arithmetic of a 2x2 solve) plus an absolute floor of ABSOLUTE for values that should be 0.
 */
final class CalibratorTest extends TestCase
{
    private const RELATIVE = 1e-9;

    private const ABSOLUTE = 1e-12;

    private const CORES = 4.0;

    /** Routes of the scenarios: [rps, queries, rows]. The mixes differ, so the two rates are not proportional. */
    private const SCENARIO_ROUTES = [
        'normal' => [[5.0, 3.0, 120.0], [1.0, 12.0, 4000.0]],
        'busy' => [[20.0, 3.0, 300.0], [2.0, 12.0, 90000.0], [0.5, 40.0, 100.0]],
        'search-heavy' => [[2.0, 3.0, 60.0], [9.0, 12.0, 25000.0]],
        'static-only' => [[30.0, 2.0, 10.0]],
    ];

    /** @return array{float, float} sum(rps * queries), sum(rps * rows) */
    private static function rates(string $name): array
    {
        $queryRate = 0.0;
        $rowRate = 0.0;
        foreach (self::SCENARIO_ROUTES[$name] as [$rps, $queries, $rows]) {
            $queryRate += $rps * $queries;
            $rowRate += $rps * $rows;
        }

        return [$queryRate, $rowRate];
    }

    /** A calibration scenario whose observed utilisation is made from the known coefficients (they may be any sign). */
    private static function point(string $name, float $cQuery, float $cRow): CalibrationPoint
    {
        $load = 0.0;
        foreach (self::SCENARIO_ROUTES[$name] as [$rps, $queries, $rows]) {
            $load += $rps * ($cQuery * $queries + $cRow * $rows);
        }

        return CalibrationPoint::fromRoutes(
            $name,
            self::CORES,
            $load / self::CORES,
            array_map(static fn (array $r): array => ['rps' => $r[0], 'queries' => $r[1], 'rows' => $r[2]], self::SCENARIO_ROUTES[$name]),
        );
    }

    private static function near(float $expected, float $actual, string $what): void
    {
        self::assertEqualsWithDelta($expected, $actual, max(self::ABSOLUTE, abs($expected) * self::RELATIVE), $what);
    }

    private static function fit(CalibrationResult $r, string $kind): CalibrationFit
    {
        foreach ($r->fits as $fit) {
            if ($fit->kind === $kind) {
                return $fit;
            }
        }
        self::fail('No fit of kind ' . $kind);
    }

    public function testTwoScenariosGiveBackTheKnownCoefficients(): void
    {
        $result = (new Calibrator())->calibrate([self::point('normal', 0.002, 0.000004), self::point('busy', 0.002, 0.000004)]);

        self::assertSame(CalibrationResult::METHOD_TWO, $result->method);
        $fit = self::fit($result, CalibrationFit::TWO);
        self::near(0.002, $fit->cQuery, 'c_q');
        self::near(0.000004, $fit->cRow, 'c_row');
        self::assertSame([], $fit->negativeUnconstrained);
        self::assertSame([], $fit->atZero);
        self::assertSame([], $result->warnings, 'a clean recovery must not warn');
        self::assertFalse($result->hasCutCoefficient());
    }

    public function testThreeScenariosGiveBackTheKnownCoefficientsWithZeroResidual(): void
    {
        $result = (new Calibrator())->calibrate([
            self::point('normal', 0.0015, 0.000002),
            self::point('busy', 0.0015, 0.000002),
            self::point('search-heavy', 0.0015, 0.000002),
        ]);

        $fit = self::fit($result, CalibrationFit::TWO);
        self::near(0.0015, $fit->cQuery, 'c_q');
        self::near(0.000002, $fit->cRow, 'c_row');
        self::assertCount(3, $fit->residuals);
        foreach ($fit->residuals as $row) {
            self::assertEqualsWithDelta(0.0, $row['residual_utilization'], 1e-9, 'residual of ' . $row['id']);
            self::assertEqualsWithDelta($row['observed_utilization'], $row['fitted_utilization'], 1e-9);
        }
        self::assertEqualsWithDelta(0.0, $fit->sumSquaredResidual, 1e-15);
    }

    public function testOneScenarioFixesEachCoefficientAloneAndKeepsBoth(): void
    {
        // The world has only a per-query cost (c_row = 0). One scenario cannot say so, so both single fits are returned.
        $point = self::point('busy', 0.01, 0.0);
        [$queryRate, $rowRate] = self::rates('busy');
        $load = 0.01 * $queryRate;

        $result = (new Calibrator())->calibrate([$point]);

        self::assertSame(CalibrationResult::METHOD_ONE, $result->method);
        self::assertCount(2, $result->fits);
        $q = self::fit($result, CalibrationFit::QUERIES_ONLY);
        $r = self::fit($result, CalibrationFit::ROWS_ONLY);
        self::near(0.01, $q->cQuery, 'queries-only c_q');
        self::assertSame(0.0, $q->cRow);
        self::near($load / $rowRate, $r->cRow, 'rows-only c_row (L divided by the row rate, worked by hand)');
        self::assertSame(0.0, $r->cQuery);
        // Each one-coefficient fit passes through the single observation exactly.
        self::near($point->observedUtilization, $q->residuals[0]['fitted_utilization'], 'queries-only fitted');
        self::near($point->observedUtilization, $r->residuals[0]['fitted_utilization'], 'rows-only fitted');
    }

    public function testOneScenarioPointValueIsTheMiddleOfTheTwoAndBothBoundTheRange(): void
    {
        $result = (new Calibrator())->calibrate([self::point('normal', 0.004, 0.0)]);
        [$queryRate, $rowRate] = self::rates('normal');
        $load = 0.004 * $queryRate;
        [$otherQ, $otherR] = self::rates('search-heavy');

        $p = $result->predict($otherQ, $otherR);

        $byQueries = 0.004 * $otherQ;
        $byRows = ($load / $rowRate) * $otherR;
        self::near(($byQueries + $byRows) / 2, $p['point'], 'point = middle of the two');
        self::near(min($byQueries, $byRows), $p['min'], 'min');
        self::near(max($byQueries, $byRows), $p['max'], 'max');
        self::assertNotEqualsWithDelta($byQueries, $byRows, 1e-6, 'the case must actually separate the two fits');
    }

    public function testTwoCoefficientPredictionUsesTheTwoCoefficientFitAsPointAndAllThreeAsRange(): void
    {
        $result = (new Calibrator())->calibrate([self::point('normal', 0.002, 0.000004), self::point('busy', 0.002, 0.000004)]);
        [$a, $b] = self::rates('search-heavy');

        $p = $result->predict($a, $b);

        self::near(0.002 * $a + 0.000004 * $b, $p['point'], 'point');
        self::assertCount(3, $p['by_fit']);
        self::assertGreaterThanOrEqual($p['point'], $p['max'], 'the range contains the point');
        self::assertLessThanOrEqual($p['point'], $p['min'], 'the range contains the point');
        self::assertSame(max($p['by_fit']), $p['max']);
        self::assertSame(min($p['by_fit']), $p['min']);
    }

    public function testNegativeRowCoefficientIsPinnedAtZeroAndSaidSo(): void
    {
        // Made with a NEGATIVE true c_row: physically impossible, and the plain least squares would return it.
        $points = [
            self::point('normal', 0.01, -0.000002),
            self::point('busy', 0.01, -0.000002),
            self::point('search-heavy', 0.01, -0.000002),
        ];

        $result = (new Calibrator())->calibrate($points);

        $fit = self::fit($result, CalibrationFit::TWO);
        self::assertSame(0.0, $fit->cRow, 'c_row is pinned at 0');
        self::assertSame(['c_row'], $fit->negativeUnconstrained);
        self::assertContains('c_row', $fit->atZero);
        self::assertTrue($result->hasCutCoefficient());
        self::assertNotSame([], array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'c_row') && str_contains($w, 'negative')));
        // With c_row pinned, c_q is the one-variable regression of L on the query rate, worked here from the data.
        $num = 0.0;
        $den = 0.0;
        foreach ($points as $p) {
            $num += $p->queryRate * $p->observedLoad();
            $den += $p->queryRate ** 2;
        }
        self::near($num / $den, $fit->cQuery, 'c_q is the regression through the origin on queries');
        self::assertGreaterThan(0.0, $fit->sumSquaredResidual, 'the cut leaves a residual, and it is reported');
    }

    public function testNegativeQueryCoefficientIsPinnedAtZeroAndSaidSo(): void
    {
        $points = [
            self::point('normal', -0.003, 0.00002),
            self::point('busy', -0.003, 0.00002),
            self::point('search-heavy', -0.003, 0.00002),
        ];

        $result = (new Calibrator())->calibrate($points);

        $fit = self::fit($result, CalibrationFit::TWO);
        self::assertSame(0.0, $fit->cQuery);
        self::assertSame(['c_query'], $fit->negativeUnconstrained);
        self::assertGreaterThan(0.0, $fit->cRow);
        self::assertTrue($result->hasCutCoefficient());
    }

    public function testThePinnedAnswerIsNoWorseThanAnyNonNegativePairOnAGrid(): void
    {
        // An independent oracle: a brute-force grid over non-negative pairs, built in the test.
        $points = [
            self::point('normal', 0.01, -0.000002),
            self::point('busy', 0.01, -0.000002),
            self::point('search-heavy', 0.01, -0.000002),
        ];
        $fit = self::fit((new Calibrator())->calibrate($points), CalibrationFit::TWO);

        $sse = static function (float $cq, float $cr) use ($points): float {
            $s = 0.0;
            foreach ($points as $p) {
                $s += ($p->observedLoad() - $cq * $p->queryRate - $cr * $p->rowRate) ** 2;
            }

            return $s;
        };
        $best = INF;
        for ($i = 0; $i <= 200; ++$i) {
            for ($j = 0; $j <= 200; ++$j) {
                $best = min($best, $sse($i * 0.0001, $j * 0.000001));
            }
        }

        self::assertLessThanOrEqual($best + 1e-12, $sse($fit->cQuery, $fit->cRow));
        self::assertGreaterThanOrEqual(0.0, $fit->cQuery);
        self::assertGreaterThanOrEqual(0.0, $fit->cRow);
    }

    public function testObservedLoadOfZeroPinsBothCoefficientsAndNeverHidesIt(): void
    {
        $result = (new Calibrator())->calibrate([self::point('normal', 0.0, 0.0), self::point('busy', 0.0, 0.0)]);

        $fit = self::fit($result, CalibrationFit::TWO);
        self::assertSame(0.0, $fit->cQuery);
        self::assertSame(0.0, $fit->cRow);
        self::assertEqualsCanonicalizing(['c_query', 'c_row'], $fit->atZero);
        self::assertTrue($result->hasCutCoefficient());
        self::assertNotSame([], $result->warnings);
    }

    public function testScenariosWithTheSameQueryToRowRatioFallBackToTheTwoOneCoefficientFits(): void
    {
        // 'static-only' has one route, and a scaled copy of it has the same ratio: the two rates are proportional.
        $a = CalibrationPoint::fromRoutes('a', 2.0, 0.2, [['rps' => 10.0, 'queries' => 2.0, 'rows' => 50.0]]);
        $b = CalibrationPoint::fromRoutes('b', 2.0, 0.4, [['rps' => 20.0, 'queries' => 2.0, 'rows' => 50.0]]);

        $result = (new Calibrator())->calibrate([$a, $b]);

        self::assertSame(CalibrationResult::METHOD_ONE, $result->method);
        self::assertCount(2, $result->fits);
        self::assertNotSame([], array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'cannot be told apart')));
    }

    public function testRoutesWithUnknownCostAreCountedAndNotAddedAsZero(): void
    {
        $point = CalibrationPoint::fromRoutes('p', 2.0, 0.5, [
            ['rps' => 10.0, 'queries' => 3.0, 'rows' => 100.0],
            ['rps' => 50.0, 'queries' => null, 'rows' => null],
            ['rps' => 1.0, 'queries' => 7.0, 'rows' => 20.0],
        ]);

        self::assertSame(10.0 * 3.0 + 1.0 * 7.0, $point->queryRate);
        self::assertSame(10.0 * 100.0 + 1.0 * 20.0, $point->rowRate);
        self::assertSame(1, $point->unknownCostRoutes);
        self::assertSame(1.0, $point->observedLoad());

        $result = (new Calibrator())->calibrate([$point]);
        self::assertSame(1, $result->unknownCostRoutes);
        self::assertSame(1, $result->toArray()['unknown_cost_routes']);
    }

    public function testResidualsAreWrittenInLoadAndInUtilisation(): void
    {
        // One scenario, queries only: observed 0.25 of 4 cores = load 1.0; the fit passes through it, so the residual is 0.
        // For the two-fit case, perturb one scenario by a known +0.04 utilisation and read the residual back.
        $good = self::point('normal', 0.002, 0.000004);
        $perturbed = new CalibrationPoint('busy', self::rates('busy')[0], self::rates('busy')[1], self::point('busy', 0.002, 0.000004)->observedUtilization + 0.04, self::CORES);
        $third = self::point('search-heavy', 0.002, 0.000004);

        $result = (new Calibrator())->calibrate([$good, $perturbed, $third]);

        $fit = self::fit($result, CalibrationFit::TWO);
        $ids = array_column($fit->residuals, 'id');
        self::assertSame(['normal', 'busy', 'search-heavy'], $ids);
        foreach ($fit->residuals as $row) {
            self::assertEqualsWithDelta($row['residual_load'] / self::CORES, $row['residual_utilization'], 1e-12);
            self::assertEqualsWithDelta($row['observed_load'] - $row['fitted_load'], $row['residual_load'], 1e-12);
        }
        // The perturbation cannot vanish: three points, two coefficients, and the data no longer lie on a plane.
        self::assertGreaterThan(1e-4, $fit->sumSquaredResidual);
    }

    public function testTheSameInputGivesTheSameOutput(): void
    {
        $points = [self::point('normal', 0.002, 0.000004), self::point('busy', 0.002, 0.000004), self::point('search-heavy', 0.002, 0.000004)];

        self::assertSame(
            json_encode((new Calibrator())->calibrate($points)->toArray(), JSON_THROW_ON_ERROR),
            json_encode((new Calibrator())->calibrate($points)->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function testToArrayCarriesTheMethodTheFitsTheResidualsAndTheCutFlag(): void
    {
        $array = (new Calibrator())->calibrate([self::point('normal', 0.002, 0.000004), self::point('busy', 0.002, 0.000004)])->toArray();

        self::assertSame('two_coefficient', $array['method']);
        self::assertSame(2, $array['calibration_scenarios']);
        self::assertFalse($array['cut_coefficients']);
        self::assertSame(['two_coefficient', 'queries_only', 'rows_only'], array_column($array['fits'], 'kind'));
        self::assertArrayHasKey('residuals', $array['fits'][0]);
        self::assertArrayHasKey('negative_unconstrained', $array['fits'][0]);
    }

    public function testInvalidInputIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Calibrator())->calibrate([]);
    }

    public function testNegativeObservedUtilisationIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CalibrationPoint('x', 1.0, 1.0, -0.1, 2.0);
    }

    public function testZeroCoresAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CalibrationPoint('x', 1.0, 1.0, 0.1, 0.0);
    }
}
