<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Backtest;

use PeakSim\Runtime\Backtest\NaiveEstimate;
use PHPUnit\Framework\TestCase;

/** Expected values are worked out by hand from the formula in simulator-spec.md section 7.3, not read off a run. */
final class NaiveEstimateTest extends TestCase
{
    public function testOneCalibrationScenarioScalesByTheRatioOfTheSumsOfRps(): void
    {
        // 0.21 * (21 / 6) = 0.735
        $result = NaiveEstimate::estimate([['id' => 'normal-1', 'rps_total' => 6.0, 'utilization' => 0.21]], 21.0);

        self::assertEqualsWithDelta(0.735, $result['utilization'], 1e-12);
        self::assertSame('normal-1', $result['basis_id']);
        self::assertSame(6.0, $result['basis_rps_total']);
        self::assertSame(21.0, $result['target_rps_total']);
    }

    public function testTwoCalibrationScenariosUseTheOneWithTheLargerSumOfRps(): void
    {
        $calibrations = [
            ['id' => 'small', 'rps_total' => 6.0, 'utilization' => 0.21],
            ['id' => 'big', 'rps_total' => 12.0, 'utilization' => 0.30],
        ];
        // big: 0.30 * (24 / 12) = 0.60   (small would give 0.84)
        $result = NaiveEstimate::estimate($calibrations, 24.0);

        self::assertEqualsWithDelta(0.60, $result['utilization'], 1e-12);
        self::assertSame('big', $result['basis_id']);
    }

    public function testTheChoiceDoesNotDependOnTheOrderOfTheScenarios(): void
    {
        $calibrations = [
            ['id' => 'big', 'rps_total' => 12.0, 'utilization' => 0.30],
            ['id' => 'small', 'rps_total' => 6.0, 'utilization' => 0.21],
        ];

        self::assertSame('big', NaiveEstimate::estimate($calibrations, 24.0)['basis_id']);
    }

    public function testEqualSumsOfRpsUseTheLargerObservedUtilization(): void
    {
        $calibrations = [
            ['id' => 'low', 'rps_total' => 10.0, 'utilization' => 0.20],
            ['id' => 'high', 'rps_total' => 10.0, 'utilization' => 0.35],
        ];
        // high: 0.35 * (30 / 10) = 1.05 (it may exceed 1: it is an estimate of load, not a clipped value)
        $result = NaiveEstimate::estimate($calibrations, 30.0);
        $reversed = NaiveEstimate::estimate(array_reverse($calibrations), 30.0);

        self::assertSame('high', $result['basis_id']);
        self::assertEqualsWithDelta(1.05, $result['utilization'], 1e-12);
        self::assertSame('high', $reversed['basis_id']);
    }

    public function testSumsThatDifferOnlyByRoundingCountAsEqual(): void
    {
        // 0.1 + 0.2 is not exactly 0.3 in floating point; they are the same sum for the rule
        $calibrations = [
            ['id' => 'low', 'rps_total' => 0.1 + 0.2, 'utilization' => 0.20],
            ['id' => 'high', 'rps_total' => 0.3, 'utilization' => 0.35],
        ];

        self::assertSame('high', NaiveEstimate::estimate($calibrations, 0.6)['basis_id']);
        self::assertSame('high', NaiveEstimate::estimate(array_reverse($calibrations), 0.6)['basis_id']);
    }

    public function testATargetWithNoRequestsGivesZero(): void
    {
        $result = NaiveEstimate::estimate([['id' => 'c', 'rps_total' => 5.0, 'utilization' => 0.4]], 0.0);

        self::assertSame(0.0, $result['utilization']);
    }

    public function testNoCalibrationScenarioStops(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NaiveEstimate::estimate([], 10.0);
    }

    public function testACalibrationScenarioWithNoRequestsStopsBecauseTheRatioIsUndefined(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"c"');
        NaiveEstimate::estimate([['id' => 'c', 'rps_total' => 0.0, 'utilization' => 0.4]], 10.0);
    }

    public function testRpsTotalSumsEveryRouteAndTreatsNoRoutesAsZero(): void
    {
        self::assertEqualsWithDelta(6.0, NaiveEstimate::rpsTotal(['GET /books/{slug}' => 5.0, 'GET /search' => 1]), 1e-12);
        self::assertSame(0.0, NaiveEstimate::rpsTotal([]));
    }

    public function testRpsTotalRefusesANegativeOrNonNumericRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NaiveEstimate::rpsTotal(['GET /search' => -1.0]);
    }

    public function testRpsTotalRefusesANumberInAString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NaiveEstimate::rpsTotal(['GET /search' => '5']);
    }
}
