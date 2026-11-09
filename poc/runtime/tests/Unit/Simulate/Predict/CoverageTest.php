<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\Coverage;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    public function testLowCoverageIsSetWhenTheRoutesWithNoCostCarryMoreThanTwentyPercentOfTheRps(): void
    {
        $over = Coverage::of([PredictFixtures::route('GET /a', 79.0, 1.0, 1.0), PredictFixtures::unknown('GET /b', 21.0)]);
        self::assertTrue($over['low_coverage']);
        self::assertEqualsWithDelta(0.21, $over['unknown_cost_rps_share'], 1e-12);
    }

    public function testExactlyTwentyPercentIsNotLowCoverage(): void
    {
        $at = Coverage::of([PredictFixtures::route('GET /a', 8.0, 1.0, 1.0), PredictFixtures::unknown('GET /b', 2.0)]);
        self::assertFalse($at['low_coverage']);
    }

    public function testCoverageIsCountedByRouteAndByShareOfRps(): void
    {
        $c = Coverage::of([
            PredictFixtures::route('GET /a', 6.0, 1.0, 1.0),
            PredictFixtures::route('GET /m', 3.0, 1.0, 1.0, 5.0),
            PredictFixtures::unknown('GET /x', 1.0),
        ]);
        self::assertSame(3, $c['routes']);
        self::assertSame(10.0, $c['rps_total']);
        self::assertSame(['routes' => 1, 'rps' => 6.0, 'rps_share' => 0.6], $c['unmarked']);
        self::assertSame(['routes' => 1, 'rps' => 3.0, 'rps_share' => 0.3], $c['marked']);
        self::assertSame(2, $c['by_source']['fit']['routes']);
        self::assertSame(1, $c['by_source']['unknown']['routes']);
        self::assertSame(0, $c['by_source']['static']['routes']);
        self::assertSame([['route' => 'GET /x', 'rps' => 1.0, 'reason' => 'no_estimate']], $c['unknown_cost_routes']);
    }

    public function testASourceTheListDoesNotKnowIsCountedAsFitNotLost(): void
    {
        $r = PredictFixtures::route('GET /a', 1.0, 1.0, 1.0, null, ['sources' => ['rows' => 'something-new']]);
        $c = Coverage::of([$r]);
        self::assertSame(1, $c['by_source']['fit']['routes']);
    }

    public function testNoRoutesIsNotACoverageProblem(): void
    {
        $c = Coverage::of([]);
        self::assertFalse($c['low_coverage']);
        self::assertSame(0.0, $c['unknown_cost_rps_share']);
    }
}
