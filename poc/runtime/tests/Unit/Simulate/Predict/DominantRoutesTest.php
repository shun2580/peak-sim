<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\Coefficients;
use PeakSim\Runtime\Simulate\Predict\DominantRoutes;
use PHPUnit\Framework\TestCase;

final class DominantRoutesTest extends TestCase
{
    public function testTheTopFiveAreOrderedByShareOfLoadAndTheRestAreLeftOut(): void
    {
        $c = new Coefficients('x', 1.0, 0.0); // load = rps x queries
        $routes = [
            PredictFixtures::route('GET /r1', 1.0, 10.0, 0.0),  // 10
            PredictFixtures::route('GET /r2', 2.0, 40.0, 0.0),  // 80
            PredictFixtures::route('GET /r3', 3.0, 10.0, 0.0),  // 30
            PredictFixtures::route('GET /r4', 1.0, 50.0, 0.0),  // 50
            PredictFixtures::route('GET /r5', 4.0, 5.0, 0.0),   // 20
            PredictFixtures::route('GET /r6', 1.0, 5.0, 0.0),   // 5
            PredictFixtures::route('GET /r7', 1.0, 5.0, 0.0),   // 5
            PredictFixtures::unknown('GET /r8', 100.0),
        ];
        $top = DominantRoutes::top($routes, $c);
        self::assertSame(['GET /r2', 'GET /r4', 'GET /r3', 'GET /r5', 'GET /r1'], array_column($top, 'route'));
        self::assertCount(5, $top);
        // the total is 80 + 50 + 30 + 20 + 10 + 5 + 5 = 200; the route with no cost is not in it
        self::assertEqualsWithDelta(0.4, $top[0]['share'], 1e-12);
        self::assertEqualsWithDelta(0.25, $top[1]['share'], 1e-12);
        self::assertEqualsWithDelta(0.05, $top[4]['share'], 1e-12);
    }

    public function testRoutesWithTheSameLoadKeepTheOrderOfTheirNames(): void
    {
        $c = new Coefficients('x', 1.0, 0.0);
        $routes = [PredictFixtures::route('GET /b', 1.0, 4.0, 0.0), PredictFixtures::route('GET /a', 1.0, 4.0, 0.0), PredictFixtures::route('GET /c', 1.0, 9.0, 0.0)];
        self::assertSame(['GET /c', 'GET /a', 'GET /b'], array_column(DominantRoutes::top($routes, $c), 'route'));
    }

    public function testEachRouteNamesItsHeaviestStatementAndTheMarksOfIt(): void
    {
        $c = new Coefficients('x', 0.0, 1.0); // load = rows
        $route = PredictFixtures::route('GET /a', 1.0, 3.0, 600.0, 900.0, [
            'types' => [
                PredictFixtures::type('t1', 1.0, 100.0),
                PredictFixtures::type('t2', 1.0, 500.0, true),
                PredictFixtures::type('t3', 1.0, 0.0, false, true),
            ],
        ]);
        $top = DominantRoutes::top([$route], $c);
        $h = $top[0]['heaviest_statement'];
        self::assertSame('t2', $h['id']);
        self::assertSame(['plan_changes_at_scale' => false, 'plan_unstable' => true], $h['marks']);
        self::assertTrue($top[0]['marked']);
    }

    public function testTheHeaviestStatementFollowsTheCoefficients(): void
    {
        $route = PredictFixtures::route('GET /a', 1.0, 3.0, 600.0, null, [
            'types' => [PredictFixtures::type('many', 1000.0, 10.0), PredictFixtures::type('big', 1.0, 590.0)],
        ]);
        // queries only: the type with 1000 runs; rows only: the type that reads 590 rows
        self::assertSame('many', DominantRoutes::top([$route], new Coefficients('q', 1.0, 0.0))[0]['heaviest_statement']['id']);
        self::assertSame('big', DominantRoutes::top([$route], new Coefficients('r', 0.0, 1.0))[0]['heaviest_statement']['id']);
    }

    public function testARouteWithNoTypesHasNoHeaviestStatement(): void
    {
        $top = DominantRoutes::top([PredictFixtures::route('GET /a', 1.0, 3.0, 6.0)], new Coefficients('x', 1.0, 1.0));
        self::assertNull($top[0]['heaviest_statement']);
    }
}
