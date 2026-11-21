<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Ladder;

use PeakSim\Runtime\Simulate\Ladder\StepWarmup;
use PeakSim\Runtime\Simulate\Predict\AppLimitRisk;
use PeakSim\Runtime\Simulate\Predict\Coefficients;
use PeakSim\Runtime\Tests\Unit\Simulate\Predict\PredictFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The warm-up of a stage of the sixth B (simulator-spec.md section 7.4.5) on made-up routes. One query costs one second of database time here
 * (c_q = 1, c_row = 0), so a route's CPU-time bound is {@see AppLimitRisk::PHP_CPU_OVER_DB} times its number of queries.
 */
final class StepWarmupTest extends TestCase
{
    /** @param array<string, float> $queries route => queries per request @return array<string, float> */
    private static function bounds(array $queries): array
    {
        $routes = [];
        foreach ($queries as $name => $q) {
            $routes[] = PredictFixtures::route($name, 1.0, $q, 0.0);
        }

        return StepWarmup::bounds($routes, new Coefficients('test', 1.0, 0.0));
    }

    public function testTheBoundIsTheOneTheCpuTimeMarkGivesAndARouteWithNoCostHasNone(): void
    {
        $bounds = StepWarmup::bounds([PredictFixtures::route('A', 1.0, 10.0, 0.0), PredictFixtures::unknown('U', 1.0)], new Coefficients('test', 1.0, 0.0));

        $this->assertSame(['A'], array_keys($bounds));
        $this->assertEqualsWithDelta(10 * AppLimitRisk::PHP_CPU_OVER_DB, $bounds['A'], 1e-12);
    }

    public function testAnUpperBoundUnderSixtySecondsGivesSixty(): void
    {
        $bounds = self::bounds(['A' => 1.0, 'B' => 6.0]);   // 9.11 s and 54.66 s
        $w = StepWarmup::forStage($bounds, ['A' => 0.5, 'B' => 0.5]);

        $this->assertSame(60, $w['warmup_sec']);
        $this->assertSame('B', $w['warmup_basis']['route'], 'the basis is still the route with the largest bound');
        $this->assertEqualsWithDelta(6 * AppLimitRisk::PHP_CPU_OVER_DB, $w['warmup_basis']['upper_bound_sec'], 1e-12);
    }

    public function testAnUpperBoundOverSixtySecondsIsRoundedUpToAWholeSecond(): void
    {
        $w = StepWarmup::forStage(self::bounds(['A' => 10.0]), ['A' => 1.0]);   // 91.1 s

        $this->assertSame(92, $w['warmup_sec']);
        $this->assertIsInt($w['warmup_sec']);
        $this->assertEqualsWithDelta(91.1, $w['warmup_basis']['upper_bound_sec'], 1e-12);
    }

    public function testTheLargestBoundOfTheRoutesInTheMixIsTaken(): void
    {
        $w = StepWarmup::forStage(self::bounds(['A' => 10.0, 'B' => 30.0, 'C' => 20.0]), ['A' => 0.2, 'B' => 0.2, 'C' => 0.6]);   // 91.1, 273.3, 182.2

        $this->assertSame(274, $w['warmup_sec']);
        $this->assertSame('B', $w['warmup_basis']['route']);
    }

    public function testARouteTakenOutOfTheMixIsNotCounted(): void
    {
        $bounds = self::bounds(['A' => 10.0, 'B' => 30.0]);   // B is out of the stage's mix: it is not among the routes of the stage
        $w = StepWarmup::forStage($bounds, ['A' => 1.0]);

        $this->assertSame(92, $w['warmup_sec']);
        $this->assertSame('A', $w['warmup_basis']['route']);
    }

    public function testARouteAtZeroRpsIsNotCounted(): void
    {
        $w = StepWarmup::forStage(self::bounds(['A' => 10.0, 'B' => 30.0]), ['A' => 1.0, 'B' => 0.0]);

        $this->assertSame('A', $w['warmup_basis']['route']);
    }

    public function testATieIsTheFirstRouteOfTheMix(): void
    {
        $w = StepWarmup::forStage(self::bounds(['A' => 10.0, 'B' => 10.0]), ['B' => 0.5, 'A' => 0.5]);

        $this->assertSame('B', $w['warmup_basis']['route'], 'the order of the stage\'s mix decides');
    }

    public function testNoRouteWithABoundGivesTheMinimumAndNoBasis(): void
    {
        $w = StepWarmup::forStage([], ['U' => 1.0]);

        $this->assertSame(['warmup_sec' => 60, 'warmup_basis' => ['route' => null, 'upper_bound_sec' => null]], $w);
    }

    public function testTheWarmupDoesNotDependOnTheRpsOfTheStage(): void
    {
        $bounds = self::bounds(['A' => 10.0, 'B' => 30.0]);

        $this->assertSame(StepWarmup::forStage($bounds, ['A' => 0.05, 'B' => 0.05]), StepWarmup::forStage($bounds, ['A' => 5.0, 'B' => 5.0]));
    }
}
