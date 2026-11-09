<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Load;

use PeakSim\Runtime\Simulate\Load\EstimateFormulas;
use PHPUnit\Framework\TestCase;

/**
 * The width of section 6.3 that the estimate gives (`queries_low` / `queries_high` / `rows_low` / `rows_high`): the least and the most over the
 * four formulas of the fitted values and over the statistics states of a plan_unstable type. Every figure is worked out by hand at N = 1,000.
 *
 *   GET /w   table pages. Types:
 *     w1  count: full 1 x N; two-scale formulas 0.5 x N, 2 x N, 1 x N        rows per run: full 10 + 0.1 N; two-scale 10 + 0.05 N, 10 + 0.2 N
 *     w2  count 1; rows per run N; states (plan_unstable) default ratio 1, low ratio 0.5, high ratio 4
 *     w3  count 1; rows per run 0; one state with no ratio (the point is 0) that reads 40 rows
 */
final class EstimateFormulasWidthTest extends TestCase
{
    private static function formulas(): EstimateFormulas
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

    public function test_the_width_is_the_least_and_the_most_over_the_four_formulas_and_the_states(): void
    {
        $w = self::formulas()->evaluate('GET /w', 1000);
        // queries: w1 500..2,000, w2 1, w3 1
        $this->assertEqualsWithDelta(502.0, $w['queries_low'], 1e-9);
        $this->assertEqualsWithDelta(2002.0, $w['queries_high'], 1e-9);
        // rows: w1 60 x 500 .. 210 x 2,000 = 30,000 .. 420,000; w2 500 .. 4,000 (ratio kept: N x 0.5, N x 4); w3 0 .. 40 (a state with no ratio is held as it is)
        $this->assertEqualsWithDelta(30500.0, $w['rows_low'], 1e-9);
        $this->assertEqualsWithDelta(424040.0, $w['rows_high'], 1e-9);
    }

    public function test_the_point_stays_what_it_was_and_lies_inside_the_width(): void
    {
        $w = self::formulas()->evaluate('GET /w', 1000);
        // point: w1 1,000 x 110 = 110,000; w2 1 x 1,000; w3 0 -> 111,000; queries 1,002
        $this->assertEqualsWithDelta(1002.0, $w['queries'], 1e-9);
        $this->assertEqualsWithDelta(111000.0, $w['rows'], 1e-9);
        $this->assertNull($w['rows_upper']);
        $this->assertGreaterThanOrEqual($w['queries_low'], $w['queries']);
        $this->assertLessThanOrEqual($w['queries_high'], $w['queries']);
        $this->assertGreaterThanOrEqual($w['rows_low'], $w['rows']);
        $this->assertLessThanOrEqual($w['rows_high'], $w['rows']);
    }

    public function test_the_formulas_and_the_states_are_put_at_each_scenarios_own_size(): void
    {
        $w = self::formulas()->evaluate('GET /w', 100);
        // N = 100: w1 count 50..200, rows per run 10 + 5 .. 10 + 20 = 15..30 -> 750 .. 6,000; w2 50 .. 400; w3 0 .. 40
        $this->assertEqualsWithDelta(52.0, $w['queries_low'], 1e-9);
        $this->assertEqualsWithDelta(202.0, $w['queries_high'], 1e-9);
        $this->assertEqualsWithDelta(800.0, $w['rows_low'], 1e-9);
        $this->assertEqualsWithDelta(6440.0, $w['rows_high'], 1e-9);
    }

    public function test_a_route_with_no_such_formula_or_state_has_no_width_and_the_others_are_not_touched(): void
    {
        $f = self::formulas();
        foreach (['GET /a', 'POST /m'] as $route) {
            $r = $f->evaluate($route, 1000);
            $this->assertNull($r['queries_low'], $route);
            $this->assertNull($r['queries_high'], $route);
            $this->assertNull($r['rows_low'], $route);
            $this->assertNull($r['rows_high'], $route);
        }
        // a route that cannot be costed has none either, and never a 0
        $this->assertNull($f->evaluate('GET /inc', 1000)['rows_low']);
        $this->assertNull($f->evaluate('GET /nopred', 1000)['rows_high']);
    }
}
