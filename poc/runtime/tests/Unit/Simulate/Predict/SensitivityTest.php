<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\Coefficients;
use PeakSim\Runtime\Simulate\Predict\Sensitivity;
use PHPUnit\Framework\TestCase;

final class SensitivityTest extends TestCase
{
    /**
     * GET /a costs one query per row of `items`; GET /b costs 3000 queries whatever the tables are.
     *
     * @param array<string, int>   $tables
     * @param array<string, float> $rps
     *
     * @return list<array<string, mixed>>
     */
    private static function evaluate(array $tables, array $rps): array
    {
        return [
            PredictFixtures::route('GET /a', $rps['GET /a'], (float) $tables['items'], 0.0),
            PredictFixtures::route('GET /b', $rps['GET /b'], 3000.0, 0.0),
        ];
    }

    public function testTheInputsAreRankedByTheChangeOfTheLimitMultiplier(): void
    {
        // load = 0.0001 x (1.0 x 1000 + 1.0 x 3000) = 0.4; limit: 0.4 x k/10 > 0.8 first at k = 21, 2.1x
        //  rps of /a +10%:   0.0001 x 4100 = 0.41 -> k = 20 (2.0x)   change -0.1
        //  rps of /b +10%:   0.0001 x 4300 = 0.43 -> k = 19 (1.9x)   change -0.2
        //  items +10% (1100): 0.41          -> 2.0x                    change -0.1
        //  users +10%: no route uses it -> no change, left out
        $tables = ['items' => 1000, 'users' => 50];
        $routes = self::evaluate($tables, ['GET /a' => 1.0, 'GET /b' => 1.0]);
        $s = Sensitivity::top($tables, $routes, new Coefficients('x', 0.0001, 0.0), 1.0, 0.8, self::evaluate(...));
        self::assertSame(['rps GET /b', 'rps GET /a', 'table items'], array_column($s['top'], 'input'));
        self::assertEqualsWithDelta([-0.2, -0.1, -0.1], array_column($s['top'], 'change'), 1e-9);
        self::assertSame(2.1, $s['top'][0]['limit_before']);
        self::assertSame(1.9, $s['top'][0]['limit_after']);
        self::assertSame([], $s['not_evaluated']);
    }

    public function testATableCountGoesUpByTenPercentRoundedUp(): void
    {
        $seen = [];
        $evaluate = static function (array $tables, array $rps) use (&$seen): array {
            $seen[] = $tables;

            return self::evaluate($tables + ['items' => 1], $rps);
        };
        $tables = ['items' => 1005, 'users' => 3];
        Sensitivity::top($tables, self::evaluate($tables, ['GET /a' => 1.0, 'GET /b' => 1.0]), new Coefficients('x', 0.0001, 0.0), 1.0, 0.8, $evaluate);
        // 1005 x 1.1 = 1105.5 -> 1106;  3 x 1.1 = 3.3 -> 4
        self::assertSame([['items' => 1106, 'users' => 3], ['items' => 1005, 'users' => 4]], $seen);
    }

    public function testWithoutAnEvaluatorTheTablesAreListedAsNotEvaluated(): void
    {
        $tables = ['items' => 1000, 'users' => 50];
        $routes = self::evaluate($tables, ['GET /a' => 1.0, 'GET /b' => 1.0]);
        $s = Sensitivity::top($tables, $routes, new Coefficients('x', 0.0001, 0.0), 1.0, 0.8, null);
        self::assertSame(['table items', 'table users'], $s['not_evaluated']);
        self::assertSame(['rps GET /b', 'rps GET /a'], array_column($s['top'], 'input'));
    }

    public function testALimitThatIsNotReachedWithinTenTimesBecomesOneThatIs(): void
    {
        // 0.0799 today: x10 = 0.799, under 0.8, so "not within 10x". rps +10% -> 0.08789; over 0.8 at k = 92 (9.2x)
        $routes = [PredictFixtures::route('GET /a', 1.0, 0.0799, 0.0)];
        $s = Sensitivity::top([], $routes, new Coefficients('x', 1.0, 0.0), 1.0, 0.8, null);
        self::assertSame('rps GET /a', $s['top'][0]['input']);
        self::assertNull($s['top'][0]['limit_before']);
        self::assertSame(9.2, $s['top'][0]['limit_after']);
        self::assertEqualsWithDelta(-0.9, $s['top'][0]['change'], 1e-9);
    }

    public function testOnlyTheTopFiveAreKept(): void
    {
        $routes = [];
        for ($i = 1; $i <= 8; $i++) {
            $routes[] = PredictFixtures::route(sprintf('GET /r%d', $i), 1.0, 100.0 * $i, 0.0);
        }
        $s = Sensitivity::top([], $routes, new Coefficients('x', 0.001, 0.0), 1.0, 0.8, null);
        self::assertCount(5, $s['top']);
    }

    public function testTheSameInputGivesTheSameRanking(): void
    {
        $tables = ['items' => 1000, 'users' => 50];
        $routes = self::evaluate($tables, ['GET /a' => 1.0, 'GET /b' => 1.0]);
        $a = Sensitivity::top($tables, $routes, new Coefficients('x', 0.0001, 0.0), 1.0, 0.8, self::evaluate(...));
        $b = Sensitivity::top($tables, $routes, new Coefficients('x', 0.0001, 0.0), 1.0, 0.8, self::evaluate(...));
        self::assertSame(json_encode($a), json_encode($b));
    }
}
