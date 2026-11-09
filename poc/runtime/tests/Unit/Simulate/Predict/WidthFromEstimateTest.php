<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Load\EstimateFormulas;
use PeakSim\Runtime\Simulate\Predict\EstimateAdapter;
use PeakSim\Runtime\Simulate\Predict\ScenarioPredictor;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The width of the estimate (`queries_low` / `queries_high` / `rows_low` / `rows_high` of EstimateFormulas::evaluate, D135) joined to the
 * prediction's receiver (ScenarioPredictor, D133): the routes are the ones the estimate itself gives, nothing is typed in by hand between them.
 * Every expected number is worked out by hand at N = 1,000 pages, with 0.001 per query, 0.0001 per row, 1 core and a single fit (so the
 * calibration adds no spread of its own).
 *
 *   GET /w (rps 2): queries 1,002 (low 502, high 2,002); rows 111,000 (low 30,500, high 424,040); not marked, so the upper side is the point
 *     point = 2 x (0.001 x 1,002 + 0.0001 x 111,000)  = 2 x 12.102 = 24.204
 *     low   = 2 x (0.001 x   502 + 0.0001 x  30,500)  = 2 x  3.552 =  7.104
 *     high  = 2 x (0.001 x 2,002 + 0.0001 x 424,040)  = 2 x 44.406 = 88.812
 *   GET /a (rps 1): no 4 formulas and no state, so no width: queries 12, rows 250 in every reading
 *     t1 count 2 x rows per run 10 + 0.1 x 1,000 = 110 -> 220 rows; t2 count 0.01 x 1,000 = 10 x 3 rows = 30 rows
 *     every reading = 1 x (0.001 x 12 + 0.0001 x 250) = 0.037
 */
final class WidthFromEstimateTest extends TestCase
{
    private const TABLES = ['pages' => 1000];

    private static function estimate(): EstimateFormulas
    {
        $doc = Fixtures::estimateDocument();
        $line = static fn (array $scales, float $a, float $b, float $k): array => ['scales' => $scales, 'a' => $a, 'b' => $b, 'k' => $k];
        $type = static fn (string $id, array $formula, bool $unstable = false): array => [
            'id' => $id, 'kind' => 'select', 'pattern' => 'select ' . $id,
            'marks' => ['plan_changes_at_scale' => false, 'plan_unstable' => $unstable],
            'formula' => $formula + ['upper' => null, 'scales' => [100, 1000, 10000], 'lines' => ['count' => [], 'per_exec' => []], 'states' => null],
        ];
        $doc['routes'][] = [
            'route' => 'GET /w', 'status' => 'captured', 'complete' => true, 'marked' => false, 'table' => 'pages', 'axis' => 'N',
            'peak_memory' => ['status' => 'not_given'], 'peak_memory_formula' => null,
            'types' => [
                $type('w1', [
                    'count' => ['a' => 0.0, 'b' => 1.0, 'k' => 1.0],
                    'per_exec' => ['basis' => 'fit', 'a' => 10.0, 'b' => 0.1, 'k' => 1.0],
                    'lines' => [
                        'count' => [$line([100, 1000], 0.0, 0.5, 1.0), $line([100, 10000], 0.0, 2.0, 1.0), $line([1000, 10000], 0.0, 1.0, 1.0)],
                        'per_exec' => [$line([100, 1000], 10.0, 0.05, 1.0), $line([100, 10000], 10.0, 0.2, 1.0), $line([1000, 10000], 10.0, 0.1, 1.0)],
                    ],
                ]),
                $type('w2', [
                    'count' => ['a' => 1.0, 'b' => 0.0, 'k' => 0.0],
                    'per_exec' => ['basis' => 'fit', 'a' => 0.0, 'b' => 1.0, 'k' => 1.0],
                    'states' => ['default' => ['at_n' => 200000.0, 'ratio' => 1.0], 'high' => ['at_n' => 800000.0, 'ratio' => 4.0], 'low' => ['at_n' => 100000.0, 'ratio' => 0.5]],
                ], true),
                $type('w3', [
                    'count' => ['a' => 1.0, 'b' => 0.0, 'k' => 0.0],
                    'per_exec' => ['basis' => 'fit', 'a' => 0.0, 'b' => 0.0, 'k' => 0.0],
                    'states' => ['odd' => ['at_n' => 40.0, 'ratio' => null]],
                ], true),
            ],
        ];
        $doc['route_index'][] = ['route' => 'GET /w', 'axis' => 'N', 'table' => 'pages', 'in_estimate' => true];

        return EstimateFormulas::fromDocument($doc);
    }

    /**
     * @param array<string, float> $rps
     *
     * @return array<string, mixed>
     */
    private static function predict(array $rps): array
    {
        $estimate = self::estimate();
        $evaluate = EstimateAdapter::evaluator($estimate);
        $scenario = ['id' => 'peak-w', 'kind' => 'target', 'tables' => self::TABLES, 'unknown_tables' => [], 'zero_rps_routes' => [], 'routes' => $evaluate(self::TABLES, $rps)];

        return ScenarioPredictor::predict($scenario, PredictFixtures::twoCoefficient(0.001, 0.0001), 1.0, 0.8, $evaluate);
    }

    public function testTheRangeIsTheLeastAndTheMostOfTheEstimatesOwnReadingsAndNamesThem(): void
    {
        $u = self::predict(['GET /w' => 2.0])['utilization'];
        self::assertEqualsWithDelta(24.204, $u['point'], 1e-9);
        self::assertEqualsWithDelta(24.204, $u['upper'], 1e-9);
        self::assertEqualsWithDelta(7.104, $u['range']['min'], 1e-9);
        self::assertEqualsWithDelta(88.812, $u['range']['max'], 1e-9);
        self::assertEqualsWithDelta(7.104, $u['by_reading']['low'], 1e-9);
        self::assertEqualsWithDelta(88.812, $u['by_reading']['high'], 1e-9);
        // the point lies inside the range, and the output says which readings made it
        self::assertGreaterThanOrEqual($u['range']['min'], $u['point']);
        self::assertLessThanOrEqual($u['range']['max'], $u['point']);
        self::assertSame(['point', 'upper', 'low', 'high'], $u['range_basis']['estimate']);
        self::assertSame(['two_coefficient'], $u['range_basis']['calibration_fits']);
    }

    public function testARouteWithNoWidthKeepsItsPointInEveryReadingAndTheBasisSaysThereIsNoLowOrHigh(): void
    {
        $u = self::predict(['GET /a' => 1.0])['utilization'];
        foreach (['point', 'upper', 'low', 'high'] as $reading) {
            self::assertEqualsWithDelta(0.037, $u['by_reading'][$reading], 1e-9, $reading);
        }
        self::assertEqualsWithDelta(0.037, $u['range']['min'], 1e-9);
        self::assertEqualsWithDelta(0.037, $u['range']['max'], 1e-9);
        self::assertSame(['point', 'upper'], $u['range_basis']['estimate']);
    }

    public function testARouteWithAWidthAndOneWithoutAreAddedReadingByReading(): void
    {
        $p = self::predict(['GET /w' => 2.0, 'GET /a' => 1.0]);
        $u = $p['utilization'];
        self::assertEqualsWithDelta(24.241, $u['point'], 1e-9);
        self::assertEqualsWithDelta(7.141, $u['range']['min'], 1e-9);
        self::assertEqualsWithDelta(88.849, $u['range']['max'], 1e-9);
        self::assertSame(['point', 'upper', 'low', 'high'], $u['range_basis']['estimate']);
        // the sensitivity re-evaluates the routes through the same evaluator, and the width does not get in its way
        self::assertIsArray($p['sensitivity']);
        // the same input gives the same output
        self::assertSame($p, self::predict(['GET /w' => 2.0, 'GET /a' => 1.0]));
    }
}
