<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\RowEstimator;
use PeakSim\Runtime\Judge\CostFit;
use PHPUnit\Framework\TestCase;

/**
 * `--with-formulas`: the formulas behind the figures are added and nothing else changes. Made-up capture: one marked type whose upper side
 * is the ratio (measured 1,000 rows per run at 10,000; a full scan of 2,000 then 8,000 rows reads 2,002 then 8,002 by PlanReads, so the upper side is
 * 1,000 x 8,002 / 2,002 per run at N = 100,000), one unmarked type.
 */
final class RowEstimatorFormulasTest extends TestCase
{
    private const N = 100000;

    /** @return array<string, mixed> */
    private static function plan(int $rows): array
    {
        return ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 't', 'access_type' => 'ALL', 'rows_examined_per_scan' => $rows, 'rows_produced_per_join' => $rows, 'filtered' => '100.00']]];
    }

    /**
     * @param list<int|float> $perExec rows per run at 100 / 1,000 / 10,000
     *
     * @return array<string, mixed>
     */
    private static function captureType(string $id, float $a, float $b, float $k, array $perExec): array
    {
        $perScale = [];
        foreach ([100, 1000, 10000] as $i => $scale) {
            $perScale[(string) $scale] = ['count' => 10, 'texts' => 1, 'rows_sum' => $perExec[$i] * 10, 'rows_min' => 0, 'rows_max' => 0, 'unread' => 0];
        }

        return [
            'id' => $id, 'kind' => 'select', 'pattern' => 'select ' . $id,
            'representative' => ['scale' => 10000, 'sql' => 'select ' . $id, 'bindings' => []],
            'per_scale' => $perScale,
            'count_fit' => ['fit_status' => 'ok', 'a' => $a, 'b' => $b, 'k' => $k, 'sse' => 0.0, 'scales' => [100, 1000, 10000]],
        ];
    }

    /** @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>} capture, explain, predictions */
    private static function fixture(): array
    {
        $capture = ['capture_version' => 1, 'app' => 'demo', 'scales' => [100, 1000, 10000], 'representative_scale' => 10000, 'routes' => [[
            'route' => 'GET /r', 'status' => 'captured',
            'statement_types' => [
                self::captureType('sM', 2.0, 0.0, 0.0, [1000, 1000, 1000]),
                self::captureType('sU', 0.0, 0.01, 1.0, [3, 3, 3]),
            ],
        ]]];
        $marks = static fn (bool $changes): array => ['plan_unstable' => false, 'plan_changes_at_scale' => $changes, 'explain_unavailable' => null, 'unavailable_states' => [], 'unstable_states' => []];
        $state = static fn (int $rows): array => ['summary' => [], 'raw' => self::plan($rows)];
        $explain = ['explain_version' => 1, 'scale' => self::N, 'small_scale' => 10000, 'routes' => [[
            'route' => 'GET /r',
            'statements' => [
                ['id' => 'sM', 'marks' => $marks(true), 'small_scale' => ['default' => $state(2000)], 'target_scale' => ['default' => $state(8000)]],
                ['id' => 'sU', 'marks' => $marks(false), 'small_scale' => ['default' => $state(5)], 'target_scale' => ['default' => $state(5)]],
            ],
        ]]];
        $predictions = ['schema_version' => '0.1', 'routes' => [
            ['route' => 'GET /r', 'axis' => 'N', 'table' => 'pages', 'quantities' => [
                'queries' => ['status' => 'predicted', 'value' => 1.0, 'a' => 1.0, 'b' => 0.0, 'k' => 0.0],
                'peak_memory' => ['status' => 'predicted', 'value' => 5.0, 'a' => 1000.0, 'b' => 2.0, 'k' => 1.0],
            ]],
            ['route' => 'GET /only-static', 'axis' => 'N', 'table' => 'books', 'quantities' => []],
        ]];

        return [$capture, $explain, $predictions];
    }

    public function test_without_the_flag_nothing_is_added(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $plain = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions);
        $this->assertArrayNotHasKey('route_index', $plain);
        $this->assertArrayNotHasKey('capture_scales', $plain);
        $this->assertArrayNotHasKey('table', $plain['routes'][0]);
        $this->assertArrayNotHasKey('formula', $plain['routes'][0]['types'][0]);
        $this->assertSame($plain, RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, false));
    }

    public function test_the_flag_adds_the_formulas_and_leaves_every_other_figure_as_it_was(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $plain = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions);
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, true);
        $stripped = $with;
        unset($stripped['capture_scales'], $stripped['route_index']);
        foreach ($stripped['routes'] as &$route) {
            unset($route['table'], $route['axis'], $route['peak_memory_formula']);
            foreach ($route['types'] as &$type) {
                unset($type['formula']);
            }
        }
        unset($route, $type);
        $this->assertSame($plain, $stripped);
    }

    public function test_axes_only_without_the_flag_nothing_changes(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        foreach ([false, true] as $withFormulas) {
            $this->assertSame(
                RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, $withFormulas),
                RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, $withFormulas, false),
            );
        }
    }

    public function test_axes_only_takes_the_table_and_the_axis_and_none_of_the_quantities_of_the_predictions(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $full = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, true);
        $axes = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, true, true);
        $route = $axes['routes'][0];
        $this->assertSame('pages', $route['table']);
        $this->assertSame('N', $route['axis']);
        $this->assertNull($route['peak_memory_formula']);
        $this->assertSame(['status' => 'not_given'], $route['peak_memory']);
        $this->assertSame(['status' => 'not_given'], $route['queries_route_fit']);
        $this->assertSame($full['route_index'], $axes['route_index']);
        // every other figure is the one of the full run: the types (counts, rows per run, formulas) come from the capture and the explain only
        $strip = static function (array $e): array {
            foreach ($e['routes'] as &$r) {
                unset($r['peak_memory'], $r['queries_route_fit'], $r['peak_memory_formula']);
            }

            return $e;
        };
        $this->assertSame($strip($full), $strip($axes));
    }

    public function test_axes_only_lets_no_figure_of_the_predictions_into_the_output(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $predictions['routes'][0]['quantities']['peak_memory'] = ['status' => 'predicted', 'value' => 424242.0, 'a' => 987654.0, 'b' => 3.5, 'k' => 1.0];
        $predictions['routes'][0]['quantities']['queries'] = ['status' => 'predicted', 'value' => 777777.0, 'a' => 555555.0, 'b' => 0.0, 'k' => 0.0];
        $json = json_encode(RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, true, true), JSON_THROW_ON_ERROR);
        foreach (['424242', '987654', '777777', '555555'] as $figure) {
            $this->assertStringNotContainsString($figure, $json);
        }
    }

    public function test_the_formulas_are_the_ones_the_figures_came_from(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, true);
        $route = $with['routes'][0];
        $this->assertSame('pages', $route['table']);
        $this->assertSame('N', $route['axis']);
        $this->assertSame(['a' => 1000.0, 'b' => 2.0, 'k' => 1.0], $route['peak_memory_formula']);
        [$sM, $sU] = $route['types'];

        // sM: count 2, rows per run 1,000 (constant), upper 1,000 x 8,002 / 2,002: the ratio is 8,002 / 2,002
        $upper = 1000.0 * 8002 / 2002;
        $this->assertSame(['a' => 2.0, 'b' => 0.0, 'k' => 0.0], $sM['formula']['count']);
        $this->assertEqualsWithDelta(1000.0, $sM['per_exec'], 1e-9);
        $this->assertEqualsWithDelta($upper, $sM['per_exec_upper'], 1e-9);
        $this->assertEqualsWithDelta($upper, $sM['formula']['upper']['at_n'], 1e-9);
        $this->assertEqualsWithDelta(1000.0, $sM['formula']['upper']['h1_at_n'], 1e-9);
        $this->assertEqualsWithDelta(8002.0 / 2002.0, $sM['formula']['upper']['ratio'], 1e-9);
        $this->assertSame([100, 1000, 10000], $sM['formula']['scales']);
        // sU: count 0.01 x N, rows per run 3, no upper side
        $this->assertSame(['a' => 0.0, 'b' => 0.01, 'k' => 1.0], $sU['formula']['count']);
        $this->assertNull($sU['formula']['upper']);
        $this->assertSame('fit', $sU['formula']['per_exec']['basis']);
    }

    public function test_the_route_index_lists_every_route_of_the_static_analysis_and_every_route_of_the_estimate(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, true);
        $this->assertSame([100, 1000, 10000], $with['capture_scales']);
        $this->assertSame([
            ['route' => 'GET /only-static', 'axis' => 'N', 'table' => 'books', 'in_estimate' => false],
            ['route' => 'GET /r', 'axis' => 'N', 'table' => 'pages', 'in_estimate' => true],
        ], $with['route_index']);
    }

    /**
     * A type whose count of runs and rows per run both follow a + b N^k exactly, at three scales: 10 / 100 / 1,000.
     *
     * @param array<int, float|int> $counts  runs at each scale
     * @param array<int, float|int> $perExec rows per run at each scale
     *
     * @return array<string, mixed>
     */
    private static function seriesType(string $id, array $counts, array $perExec): array
    {
        $scales = [10, 100, 1000];
        $perScale = [];
        foreach ($scales as $i => $scale) {
            $perScale[(string) $scale] = ['count' => $counts[$i], 'texts' => 1, 'rows_sum' => $perExec[$i] * $counts[$i], 'rows_min' => 0, 'rows_max' => 0, 'unread' => 0];
        }
        $f = CostFit::fit($scales, $counts);

        return [
            'id' => $id, 'kind' => 'select', 'pattern' => 'select ' . $id,
            'representative' => ['scale' => 1000, 'sql' => 'select ' . $id, 'bindings' => []],
            'per_scale' => $perScale,
            'count_fit' => ['fit_status' => 'ok', 'a' => CostFit::sig($f['a']), 'b' => CostFit::sig($f['b']), 'k' => CostFit::sig($f['k']), 'sse' => 0.0, 'scales' => $scales],
        ];
    }

    /** @param list<array<string, mixed>> $types */
    private static function capture(array $types): array
    {
        return ['capture_version' => 1, 'app' => 'demo', 'scales' => [10, 100, 1000], 'representative_scale' => 1000, 'routes' => [['route' => 'GET /w', 'status' => 'captured', 'statement_types' => $types]]];
    }

    /** @return array<string, mixed> an explain.json at N = 100,000 with no statement (every type then has no plan) */
    private static function bareExplain(): array
    {
        return ['explain_version' => 1, 'scale' => self::N, 'small_scale' => 1000, 'routes' => []];
    }

    public function test_the_formulas_of_two_scales_each_hold_a_at_the_full_fit_and_are_three_for_three_scales(): void
    {
        // both series are exact: count 1 x N, rows per run 3 x N -> every pair finds the same b and k as the full fit
        $capture = self::capture([self::seriesType('sE', [10, 100, 1000], [30, 300, 3000])]);
        $with = RowEstimator::estimate($capture, self::bareExplain(), RowEstimator::H1, self::N, null, true);
        $lines = $with['routes'][0]['types'][0]['formula']['lines'];
        $this->assertSame([[10, 100], [10, 1000], [100, 1000]], array_column($lines['count'], 'scales'));
        $this->assertSame([[10, 100], [10, 1000], [100, 1000]], array_column($lines['per_exec'], 'scales'));
        foreach ($lines['count'] as $l) {
            $this->assertSame(['a' => 0.0, 'b' => 1.0, 'k' => 1.0], ['a' => $l['a'], 'b' => $l['b'], 'k' => $l['k']]);
        }
        foreach ($lines['per_exec'] as $l) {
            $this->assertSame(['a' => 0.0, 'b' => 3.0, 'k' => 1.0], ['a' => $l['a'], 'b' => $l['b'], 'k' => $l['k']]);
        }
    }

    public function test_the_pairs_of_a_series_that_is_not_exact_differ_and_keep_the_a_of_the_full_fit(): void
    {
        $counts = [10, 100, 2000];
        $capture = self::capture([self::seriesType('sB', $counts, [4, 4, 90])]);
        $type = RowEstimator::estimate($capture, self::bareExplain(), RowEstimator::H1, self::N, null, true)['routes'][0]['types'][0];
        $full = $type['formula']['count'];
        $this->assertCount(3, $type['formula']['lines']['count']);
        $values = [];
        foreach ($type['formula']['lines']['count'] as $l) {
            $this->assertSame($full['a'], $l['a'], 'a is held at the full fit');
            $direct = CostFit::fitWithFixedA($l['scales'], array_map(static fn (int $s): int => $counts[array_search($s, [10, 100, 1000], true)], $l['scales']), $full['a']);
            $this->assertSame(CostFit::sig($direct['b']), $l['b']);
            $this->assertSame(CostFit::sig($direct['k']), $l['k']);
            $values[] = CostFit::evaluate($l['a'], $l['b'], $l['k'], self::N);
        }
        $this->assertGreaterThan(min($values), max($values), 'the three formulas do not agree at N: that is the width');
        // the type's own figures are those of the full fit, the same with and without the flag
        $plain = RowEstimator::estimate($capture, self::bareExplain(), RowEstimator::H1, self::N)['routes'][0]['types'][0];
        $this->assertSame($plain['count_at_n'], $type['count_at_n']);
        $this->assertSame($plain['per_exec'], $type['per_exec']);
    }

    public function test_a_type_with_fewer_than_three_scales_for_its_rows_has_no_formulas_for_them(): void
    {
        $type = self::seriesType('sH', [10, 100, 1000], [5, 5, 5]);
        unset($type['per_scale']['100']);              // did not run at 100
        $type['per_scale']['10']['rows_sum'] = null;   // the rows at 10 could not be read: only the 1,000 scale has a value
        $with = RowEstimator::estimate(self::capture([$type]), self::bareExplain(), RowEstimator::H1, self::N, null, true);
        $formula = $with['routes'][0]['types'][0]['formula'];
        $this->assertSame('held_from_largest_scale', $formula['per_exec']['basis']);
        $this->assertSame([], $formula['lines']['per_exec']);
    }

    public function test_a_plan_unstable_type_gives_its_rows_per_run_at_each_statistics_state_and_the_upper_side_is_the_heaviest(): void
    {
        // measured 1,000 rows per run at the small size (E = 2,002); at N the plan reads 4,002 / 8,002 / 16,002 in the states low / default / high
        $capture = self::capture([self::seriesType('sU', [1, 1, 1], [1000, 1000, 1000])]);
        $capture['routes'][0]['statement_types'][0]['count_fit'] = ['fit_status' => 'ok', 'a' => 1.0, 'b' => 0.0, 'k' => 0.0, 'sse' => 0.0, 'scales' => [10, 100, 1000]];
        $state = static fn (int $rows): array => ['summary' => [], 'raw' => self::plan($rows)];
        $explain = ['explain_version' => 1, 'scale' => self::N, 'small_scale' => 1000, 'routes' => [[
            'route' => 'GET /w',
            'statements' => [[
                'id' => 'sU',
                'marks' => ['plan_unstable' => true, 'plan_changes_at_scale' => false, 'explain_unavailable' => null, 'unavailable_states' => [], 'unstable_states' => ['low', 'high']],
                'small_scale' => ['default' => $state(2000)],
                'target_scale' => ['default' => $state(8000), 'low' => $state(4000), 'high' => $state(16000)],
            ]],
        ]]];
        $plain = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N);
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, null, true);
        $type = $with['routes'][0]['types'][0];
        $states = $type['formula']['states'];
        $this->assertSame(['default', 'high', 'low'], array_keys($states));
        $this->assertEqualsWithDelta(1000.0 * 8002 / 2002, $states['default']['at_n'], 1e-9);
        $this->assertEqualsWithDelta(1000.0 * 16002 / 2002, $states['high']['at_n'], 1e-9);
        $this->assertEqualsWithDelta(1000.0 * 4002 / 2002, $states['low']['at_n'], 1e-9);
        $this->assertEqualsWithDelta($states['high']['at_n'] / 1000.0, $states['high']['ratio'], 1e-9, 'the ratio is to the point (H1) at N');
        $this->assertEqualsWithDelta($states['high']['at_n'], $type['per_exec_upper'], 1e-9, 'the upper side is the heaviest state, as before');
        $this->assertArrayNotHasKey('e_by_state', $type, 'the figures of the type are as without the flag');
        $this->assertNull($plain['routes'][0]['types'][0]['formula'] ?? null);
    }

    public function test_a_type_that_is_not_plan_unstable_has_no_states(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, true);
        foreach ($with['routes'][0]['types'] as $type) {
            $this->assertNull($type['formula']['states']);
        }
    }

    /**
     * A type measured at four scales (10 / 100 / 1,000 / 10,000) whose count of runs is the given series and whose rows per run are the given
     * series. The count fit is the one given (`a` = 0), so that the figures can be worked by hand.
     *
     * @param list<int|float> $counts
     * @param list<int|float> $perExec
     *
     * @return array<string, mixed>
     */
    private static function fourScaleCapture(array $counts, array $perExec): array
    {
        $scales = [10, 100, 1000, 10000];
        $perScale = [];
        foreach ($scales as $i => $scale) {
            $perScale[(string) $scale] = ['count' => $counts[$i], 'texts' => 1, 'rows_sum' => $perExec[$i] * $counts[$i], 'rows_min' => 0, 'rows_max' => 0, 'unread' => 0];
        }
        $type = [
            'id' => 'sF', 'kind' => 'select', 'pattern' => 'select sF',
            'representative' => ['scale' => 10000, 'sql' => 'select sF', 'bindings' => []],
            'per_scale' => $perScale,
            'count_fit' => ['fit_status' => 'ok', 'a' => 0.0, 'b' => 1.0, 'k' => 1.0, 'sse' => 0.0, 'scales' => $scales],
        ];

        return ['capture_version' => 1, 'app' => 'demo', 'scales' => $scales, 'representative_scale' => 10000, 'routes' => [['route' => 'GET /f', 'status' => 'captured', 'statement_types' => [$type]]]];
    }

    public function test_a_type_of_four_scales_has_three_formulas_of_the_larger_three_scales_and_none_with_the_scale_10(): void
    {
        $capture = self::fourScaleCapture([10, 100, 1000, 10000], [3, 30, 300, 3000]);
        $lines = RowEstimator::estimate($capture, self::bareExplain(), RowEstimator::H1, self::N, null, true)['routes'][0]['types'][0]['formula']['lines'];
        $this->assertSame([[100, 1000], [100, 10000], [1000, 10000]], array_column($lines['count'], 'scales'));
        $this->assertSame([[100, 1000], [100, 10000], [1000, 10000]], array_column($lines['per_exec'], 'scales'));
        foreach (['count', 'per_exec'] as $quantity) {
            foreach ($lines[$quantity] as $l) {
                $this->assertNotContains(10, $l['scales'], 'a formula that holds the scale 10 and the full fit\'s a runs k to its limit');
            }
        }
    }

    public function test_the_formulas_of_a_type_of_four_scales_run_from_the_least_to_the_most_by_hand(): void
    {
        // runs per scale 50 / 100 / 1,000 / 5,000 with a = 0: the pair (100, 1,000) is a straight line, (1,000, 10,000) has k = log10 5,
        // (100, 10,000) has k = log10 50 / 2. At N = 100,000 they read 100 x 1,000 = 100,000, 1,000 x 100^k = 1,000 x 25 = 25,000
        // and 100 x 1,000^k = 100 x 50^1.5 = 35,355.
        $capture = self::fourScaleCapture([50, 100, 1000, 5000], [3, 3, 3, 3]);
        $lines = RowEstimator::estimate($capture, self::bareExplain(), RowEstimator::H1, self::N, null, true)['routes'][0]['types'][0]['formula']['lines']['count'];
        $this->assertCount(3, $lines);
        $at = array_map(static fn (array $l): float => CostFit::evaluate($l['a'], $l['b'], $l['k'], self::N), $lines);
        $this->assertEqualsWithDelta(100000.0, $at[0], 100000.0 * 0.01);
        $this->assertEqualsWithDelta(35355.0, $at[1], 35355.0 * 0.01);
        $this->assertEqualsWithDelta(25000.0, $at[2], 25000.0 * 0.01);
        $this->assertEqualsWithDelta(25000.0, min($at), 25000.0 * 0.01);
        $this->assertEqualsWithDelta(100000.0, max($at), 100000.0 * 0.01);
    }
}
