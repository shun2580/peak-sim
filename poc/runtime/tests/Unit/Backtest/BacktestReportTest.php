<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Backtest;

use PeakSim\Runtime\Backtest\BacktestReport;
use PHPUnit\Framework\TestCase;

/**
 * Expected values are worked out by hand from simulator-spec.md sections 7.3 and 7.4 B, with made-up inputs, not read off a run.
 */
final class BacktestReportTest extends TestCase
{
    /** @param list<array{id: string, rps: array<string, float>, observed: float}> $calibrations */
    private static function loaded(array $calibrations): array
    {
        $scenarios = [];
        foreach ($calibrations as $c) {
            $routes = [];
            foreach ($c['rps'] as $route => $rps) {
                $routes[] = ['route' => $route, 'rps' => $rps];
            }
            $scenarios[] = ['id' => $c['id'], 'kind' => 'calibration', 'routes' => $routes, 'observed' => ['db_cpu_utilization' => $c['observed']]];
        }
        $scenarios[] = ['id' => 'ignored-target', 'kind' => 'target', 'routes' => [['route' => 'GET /a', 'rps' => 999.0]]];

        return ['scenarios' => $scenarios];
    }

    /** @param list<array{id: string, rps_total: float, point: float, upper: float}> $targets */
    private static function prediction(array $targets, float $threshold = 0.8): array
    {
        $scenarios = [];
        foreach ($targets as $t) {
            $scenarios[] = ['id' => $t['id'], 'rps_total' => $t['rps_total'], 'low_coverage' => $t['low_coverage'] ?? false, 'utilization' => ['point' => $t['point'], 'upper' => $t['upper']]];
        }

        return ['app' => 'demo', 'resources' => ['threshold' => $threshold], 'scenarios' => $scenarios];
    }

    private static function oneCalibration(): array
    {
        // sum of rps 4 + 2 = 6, observed 0.20
        return self::loaded([['id' => 'c1', 'rps' => ['GET /a' => 4.0, 'GET /b' => 2.0], 'observed' => 0.20]]);
    }

    public function testDifferencesAndTheNaiveEstimateAreWorkedOutPerScenario(): void
    {
        $prediction = self::prediction([
            ['id' => 's1', 'rps_total' => 12.0, 'point' => 0.45, 'upper' => 0.50],
            ['id' => 's2', 'rps_total' => 30.0, 'point' => 0.90, 'upper' => 1.10],
        ]);
        $report = BacktestReport::build(self::oneCalibration(), $prediction, ['s1' => 0.40, 's2' => 0.85]);

        [$s1, $s2] = $report['scenarios'];
        // naive s1 = 0.20 * 12 / 6 = 0.40 (difference 0); naive s2 = 0.20 * 30 / 6 = 1.00 (difference +0.15)
        self::assertSame('s1', $s1['id']);
        self::assertEqualsWithDelta(0.40, $s1['naive']['utilization'], 1e-12);
        self::assertEqualsWithDelta(0.0, $s1['naive']['difference'], 1e-12);
        self::assertEqualsWithDelta(1.00, $s2['naive']['utilization'], 1e-12);
        self::assertEqualsWithDelta(0.15, $s2['naive']['difference'], 1e-12);
        self::assertSame('c1', $s2['naive']['basis_scenario']);
        // prediction minus observed: s1 0.45 - 0.40, s2 0.90 - 0.85
        self::assertEqualsWithDelta(0.05, $s1['predicted']['difference'], 1e-12);
        self::assertEqualsWithDelta(0.05, $s2['predicted']['absolute_difference'], 1e-12);
        self::assertEqualsWithDelta(0.50, $s1['predicted']['upper'], 1e-12);
        self::assertSame(0.40, $s1['observed']);
    }

    public function testASignedDifferenceKeepsItsSignAndTheMeanUsesTheAbsoluteValue(): void
    {
        $prediction = self::prediction([
            ['id' => 'under', 'rps_total' => 6.0, 'point' => 0.10, 'upper' => 0.10], // observed 0.30: -0.20
            ['id' => 'over', 'rps_total' => 6.0, 'point' => 0.50, 'upper' => 0.50],  // observed 0.30: +0.20
        ]);
        $report = BacktestReport::build(self::oneCalibration(), $prediction, ['under' => 0.30, 'over' => 0.30]);

        self::assertEqualsWithDelta(-0.20, $report['scenarios'][0]['predicted']['difference'], 1e-12);
        self::assertEqualsWithDelta(0.20, $report['scenarios'][1]['predicted']['difference'], 1e-12);
        self::assertEqualsWithDelta(0.20, $report['summary']['mean_absolute_difference'], 1e-12, 'a mean of the signed values would be 0');
    }

    public function testTheThreeItemsOfSection74BWhenTheyAreMet(): void
    {
        $prediction = self::prediction([
            ['id' => 's1', 'rps_total' => 12.0, 'point' => 0.45, 'upper' => 0.50],
            ['id' => 's2', 'rps_total' => 30.0, 'point' => 0.90, 'upper' => 1.10],
        ]);
        $summary = BacktestReport::build(self::oneCalibration(), $prediction, ['s1' => 0.40, 's2' => 0.85])['summary'];

        // prediction: (0.05 + 0.05) / 2 = 0.05; naive: (0 + 0.15) / 2 = 0.075
        self::assertSame(2, $summary['scenarios_compared']);
        self::assertEqualsWithDelta(0.05, $summary['mean_absolute_difference'], 1e-12);
        self::assertEqualsWithDelta(0.075, $summary['mean_absolute_difference_naive'], 1e-12);
        self::assertTrue($summary['items']['beats_naive']['value']);
        $points = $summary['items']['mean_difference_points'];
        self::assertEqualsWithDelta(5.0, $points['value'], 1e-9);
        self::assertTrue($points['meets_minimum']);
        self::assertTrue($points['meets_target']);
        // observed: s1 0.40 not over 0.8, s2 0.85 over; prediction 0.45 / 0.90 agree on both; naive 0.40 / 1.00 agree on both
        $agreement = $summary['items']['threshold_agreement'];
        self::assertTrue($agreement['applies']);
        self::assertEqualsWithDelta(1.0, $agreement['share'], 1e-12);
        self::assertTrue($agreement['meets_line']);
    }

    public function testAPredictionNoBetterThanTheNaiveEstimateDoesNotBeatIt(): void
    {
        $prediction = self::prediction([
            ['id' => 's1', 'rps_total' => 12.0, 'point' => 0.50, 'upper' => 0.50], // observed 0.40: 0.10 off; naive 0.40: 0 off
        ]);
        $summary = BacktestReport::build(self::oneCalibration(), $prediction, ['s1' => 0.40])['summary'];

        self::assertFalse($summary['items']['beats_naive']['value']);
    }

    public function testEqualMeanDifferencesDoNotCountAsBeatingTheNaiveEstimate(): void
    {
        // naive 0.20 * 12 / 6 = 0.40, observed 0.50: 0.10 off. The prediction 0.60 is also 0.10 off.
        $prediction = self::prediction([['id' => 's1', 'rps_total' => 12.0, 'point' => 0.60, 'upper' => 0.60]]);
        $summary = BacktestReport::build(self::oneCalibration(), $prediction, ['s1' => 0.50])['summary'];

        self::assertFalse($summary['items']['beats_naive']['value'], 'section 7.4 B item 1 says "smaller than"');
    }

    public function testTheMinimumLineIsFifteenPointsAndTheTargetTen(): void
    {
        $meets = static function (float $meanDifference): array {
            $prediction = self::prediction([['id' => 's1', 'rps_total' => 6.0, 'point' => 0.30 + $meanDifference, 'upper' => 0.30 + $meanDifference]]);
            $item = BacktestReport::build(self::oneCalibration(), $prediction, ['s1' => 0.30])['summary']['items']['mean_difference_points'];

            return [$item['meets_minimum'], $item['meets_target']];
        };

        self::assertSame([true, true], $meets(0.10), 'exactly 10 points is within the target');
        self::assertSame([true, false], $meets(0.15), 'exactly 15 points is within the minimum, over the target');
        self::assertSame([true, false], $meets(0.12));
        self::assertSame([false, false], $meets(0.16));
    }

    public function testTheLinesOfTheSpecAreTheValuesOfSection74(): void
    {
        self::assertSame(15.0, BacktestReport::MEAN_DIFFERENCE_MINIMUM_POINTS);
        self::assertSame(10.0, BacktestReport::MEAN_DIFFERENCE_TARGET_POINTS);
        self::assertSame(0.8, BacktestReport::AGREEMENT_SHARE_LINE);
    }

    public function testTheThresholdAgreementIsNotUsedWhenNoScenarioIsOverTheThresholdInTheObservation(): void
    {
        $prediction = self::prediction([
            ['id' => 's1', 'rps_total' => 12.0, 'point' => 0.45, 'upper' => 0.5],
            ['id' => 's2', 'rps_total' => 18.0, 'point' => 0.85, 'upper' => 0.9], // predicted over, observed not over
        ]);
        $agreement = BacktestReport::build(self::oneCalibration(), $prediction, ['s1' => 0.40, 's2' => 0.60])['summary']['items']['threshold_agreement'];

        self::assertFalse($agreement['applies']);
        self::assertArrayNotHasKey('meets_line', $agreement);
        self::assertEqualsWithDelta(0.5, $agreement['share'], 1e-12, 'the share is still shown: 1 of 2 agrees');
    }

    public function testTheThresholdAgreementLineIsEightyPercent(): void
    {
        // five scenarios, all observed over 0.8; the prediction is over in `$overCount` of them
        $agreement = static function (int $overCount): array {
            $targets = [];
            $observed = [];
            for ($i = 1; $i <= 5; ++$i) {
                $targets[] = ['id' => "s$i", 'rps_total' => 6.0, 'point' => $i <= $overCount ? 0.9 : 0.7, 'upper' => 0.9];
                $observed["s$i"] = 0.85;
            }

            return BacktestReport::build(self::oneCalibration(), self::prediction($targets), $observed)['summary']['items']['threshold_agreement'];
        };

        self::assertTrue($agreement(4)['meets_line'], '4 of 5 = 80% is on the line');
        self::assertFalse($agreement(3)['meets_line'], '3 of 5 = 60%');
        self::assertEqualsWithDelta(0.6, $agreement(3)['share'], 1e-12);
    }

    public function testAValueExactlyOnTheThresholdIsNotOver(): void
    {
        $prediction = self::prediction([['id' => 's1', 'rps_total' => 6.0, 'point' => 0.8, 'upper' => 0.8]]);
        $row = BacktestReport::build(self::oneCalibration(), $prediction, ['s1' => 0.8])['scenarios'][0];

        self::assertFalse($row['predicted']['over_threshold']);
        self::assertFalse($row['observed_over_threshold']);
    }

    public function testATargetWithoutAnObservedValueIsListedApartAndNotCounted(): void
    {
        $prediction = self::prediction([
            ['id' => 'with', 'rps_total' => 6.0, 'point' => 0.25, 'upper' => 0.3],
            ['id' => 'without', 'rps_total' => 6.0, 'point' => 0.99, 'upper' => 0.99],
        ]);
        $report = BacktestReport::build(self::oneCalibration(), $prediction, ['with' => 0.20]);

        self::assertSame(['with'], array_column($report['scenarios'], 'id'));
        self::assertSame(['without'], $report['target_scenarios_without_observed']);
        self::assertSame(1, $report['summary']['scenarios_compared']);
        self::assertEqualsWithDelta(0.05, $report['summary']['mean_absolute_difference'], 1e-12, 'the far-off scenario without observed does not enter the mean');
    }

    public function testNothingToCompareGivesNoItems(): void
    {
        $report = BacktestReport::build(self::oneCalibration(), self::prediction([['id' => 's1', 'rps_total' => 6.0, 'point' => 0.2, 'upper' => 0.2]]), []);

        self::assertSame([], $report['scenarios']);
        self::assertSame(0, $report['summary']['scenarios_compared']);
        self::assertNull($report['summary']['items']);
    }

    public function testTwoCalibrationScenariosGiveTheNaiveEstimateFromTheOneWithTheLargerSumOfRps(): void
    {
        $loaded = self::loaded([
            ['id' => 'small', 'rps' => ['GET /a' => 6.0], 'observed' => 0.21],
            ['id' => 'big', 'rps' => ['GET /a' => 8.0, 'GET /b' => 4.0], 'observed' => 0.30],
        ]);
        $row = BacktestReport::build($loaded, self::prediction([['id' => 's1', 'rps_total' => 24.0, 'point' => 0.6, 'upper' => 0.6]]), ['s1' => 0.55])['scenarios'][0];

        // big: 0.30 * 24 / 12 = 0.60
        self::assertSame('big', $row['naive']['basis_scenario']);
        self::assertEqualsWithDelta(0.60, $row['naive']['utilization'], 1e-12);
        self::assertEqualsWithDelta(12.0, $row['naive']['basis_rps_total'], 1e-12);
    }

    public function testACalibrationScenarioWithASumOfRpsOfZeroStopsTheNaiveEstimate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BacktestReport::build(self::loaded([['id' => 'c', 'rps' => ['GET /a' => 0.0], 'observed' => 0.1]]), self::prediction([['id' => 's1', 'rps_total' => 6.0, 'point' => 0.2, 'upper' => 0.2]]), ['s1' => 0.2]);
    }

    public function testALowCoverageScenarioIsFlaggedNotLeftOut(): void
    {
        $prediction = self::prediction([['id' => 's1', 'rps_total' => 6.0, 'point' => 0.25, 'upper' => 0.3, 'low_coverage' => true]]);
        $report = BacktestReport::build(self::oneCalibration(), $prediction, ['s1' => 0.20]);

        self::assertTrue($report['scenarios'][0]['low_coverage']);
        self::assertSame(1, $report['summary']['scenarios_compared']);
    }

    public function testTheSameInputsGiveTheSameJson(): void
    {
        $prediction = self::prediction([['id' => 's1', 'rps_total' => 12.0, 'point' => 0.45, 'upper' => 0.5]]);
        $a = json_encode(BacktestReport::build(self::oneCalibration(), $prediction, ['s1' => 0.4]), JSON_THROW_ON_ERROR);
        $b = json_encode(BacktestReport::build(self::oneCalibration(), $prediction, ['s1' => 0.4]), JSON_THROW_ON_ERROR);

        self::assertSame($a, $b);
    }
}
