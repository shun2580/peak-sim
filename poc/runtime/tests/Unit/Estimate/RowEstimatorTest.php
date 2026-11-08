<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\RowEstimator;
use PHPUnit\Framework\TestCase;

/**
 * H1 to H3 on a made-up capture and made-up plans. Every expected figure is worked out by hand in the comments of `types()`.
 *
 * Six statement types (the count at N is fixed, so each route total is a sum of per_exec x count):
 *
 *   id  count  marks                 rows per run measured at 100 / 1,000 / 10,000   E(small) -> E(N)
 *   sA    2    none                  10 / 100 / 1,000 (grows with N: H1 gives 10,000 at 100,000)   ref 1,000 -> ref 10,000   (1,001 -> 10,001)
 *   sB    3    plan_changes_at_scale 50 / 50 / 50                                                  ALL 1,000 -> ALL 100,000   (1,002 -> 100,002)
 *   sC    4    none                  20 / 20 / 20                                                  ref 5 -> ref 8             (6 -> 9)
 *   sD    5    plan_unstable         10 / 10 / 10                                                  ALL 100 -> default ALL 100, high ALL 400   (102 -> heaviest 402)
 *   sE    6    plan_unstable         7 / 7 / 7                                                     small plan reads nothing   (E(small) = 0)
 *   sF    7    plan_changes_at_scale 1 / 1 / 1                                                     EXPLAIN not available (a kind that is not explained)
 */
final class RowEstimatorTest extends TestCase
{
    private const N = 100000;

    /** @return array<string, mixed> */
    private static function plan(string $access, int $rows): array
    {
        return ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 't', 'access_type' => $access, 'rows_examined_per_scan' => $rows, 'rows_produced_per_join' => $rows, 'filtered' => '100.00']]];
    }

    /** @return array<string, mixed> */
    private static function state(array $plan): array
    {
        return ['summary' => [], 'raw' => $plan];
    }

    /**
     * @param list<int|float> $perExec rows per run at 100 / 1,000 / 10,000
     *
     * @return array<string, mixed>
     */
    private static function captureType(string $id, int $count, array $perExec): array
    {
        $perScale = [];
        foreach ([100, 1000, 10000] as $i => $scale) {
            $perScale[(string) $scale] = ['count' => 10, 'texts' => 1, 'rows_sum' => $perExec[$i] * 10, 'rows_min' => 0, 'rows_max' => 0, 'unread' => 0];
        }

        return [
            'id' => $id, 'kind' => 'select', 'pattern' => 'select ' . $id,
            'representative' => ['scale' => 10000, 'sql' => 'select ' . $id, 'bindings' => []],
            'per_scale' => $perScale,
            'count_fit' => ['fit_status' => 'ok', 'a' => (float) $count, 'b' => 0.0, 'k' => 0.0, 'sse' => 0.0, 'scales' => [100, 1000, 10000]],
        ];
    }

    /** @return array{array<string, mixed>, array<string, mixed>} capture and explain */
    private static function fixture(): array
    {
        $capture = ['capture_version' => 1, 'app' => 'demo', 'scales' => [100, 1000, 10000], 'representative_scale' => 10000, 'routes' => [[
            'route' => 'GET /r', 'status' => 'captured',
            'statement_types' => [
                self::captureType('sA', 2, [10, 100, 1000]),
                self::captureType('sB', 3, [50, 50, 50]),
                self::captureType('sC', 4, [20, 20, 20]),
                self::captureType('sD', 5, [10, 10, 10]),
                self::captureType('sE', 6, [7, 7, 7]),
                self::captureType('sF', 7, [1, 1, 1]),
            ],
        ]]];
        $marks = static fn (bool $changes, bool $unstable, ?string $unavailable = null): array => ['plan_unstable' => $unstable, 'plan_changes_at_scale' => $changes, 'explain_unavailable' => $unavailable, 'unavailable_states' => [], 'unstable_states' => []];
        $explain = ['explain_version' => 1, 'scale' => self::N, 'small_scale' => 10000, 'routes' => [[
            'route' => 'GET /r',
            'statements' => [
                ['id' => 'sA', 'marks' => $marks(false, false), 'small_scale' => ['default' => self::state(self::plan('ref', 1000))], 'target_scale' => ['default' => self::state(self::plan('ref', 10000))]],
                ['id' => 'sB', 'marks' => $marks(true, false), 'small_scale' => ['default' => self::state(self::plan('ALL', 1000))], 'target_scale' => ['default' => self::state(self::plan('ALL', 100000))]],
                ['id' => 'sC', 'marks' => $marks(false, false), 'small_scale' => ['default' => self::state(self::plan('ref', 5))], 'target_scale' => ['default' => self::state(self::plan('ref', 8))]],
                ['id' => 'sD', 'marks' => $marks(false, true), 'small_scale' => ['default' => self::state(self::plan('ALL', 100))], 'target_scale' => ['default' => self::state(self::plan('ALL', 100)), 'low' => self::state(self::plan('ALL', 50)), 'high' => self::state(self::plan('ALL', 400))]],
                ['id' => 'sE', 'marks' => $marks(false, true), 'small_scale' => ['default' => self::state(['query_block' => ['message' => 'Impossible WHERE']])], 'target_scale' => ['default' => self::state(self::plan('ALL', 100))]],
                ['id' => 'sF', 'marks' => $marks(true, false, 'not_explainable_kind: begin'), 'small_scale' => [], 'target_scale' => []],
            ],
        ]]];

        return [$capture, $explain];
    }

    /** @return array<string, mixed> */
    private static function route(string $candidate): array
    {
        [$capture, $explain] = self::fixture();

        return RowEstimator::estimate($capture, $explain, $candidate)['routes'][0];
    }

    /** @return array<string, array<string, mixed>> the types of the route by id */
    private static function types(string $candidate): array
    {
        $out = [];
        foreach (self::route($candidate)['types'] as $t) {
            $out[$t['id']] = $t;
        }

        return $out;
    }

    public function test_h1_is_the_fit_of_each_type_and_the_route_is_the_sum_of_per_exec_times_count(): void
    {
        // sA 10,000 x 2 + sB 50 x 3 + sC 20 x 4 + sD 10 x 5 + sE 7 x 6 + sF 1 x 7 = 20,000 + 150 + 80 + 50 + 42 + 7
        $route = self::route('H1');
        $this->assertEqualsWithDelta(20329.0, $route['rows_examined'], 1e-6);
        $this->assertEqualsWithDelta(27.0, $route['queries'], 1e-9);
        $this->assertTrue($route['complete']);
        $this->assertSame(0, $route['ratio_used']);
        $this->assertSame([], $route['fallbacks']);
    }

    public function test_h2_uses_the_ratio_for_the_marked_types_only(): void
    {
        $t = self::types('H2');
        // sA, sC carry no mark: H1 (sA grows with N, sC is flat)
        $this->assertSame('h1', $t['sA']['basis']);
        $this->assertEqualsWithDelta(10000.0, $t['sA']['per_exec'], 1e-6);
        $this->assertSame('h1', $t['sC']['basis']);
        $this->assertEqualsWithDelta(20.0, $t['sC']['per_exec'], 1e-6);
        // sB (plan_changes_at_scale): 50 x 100,002 / 1,002
        $this->assertSame('ratio', $t['sB']['basis']);
        $this->assertEqualsWithDelta(50.0 * 100002 / 1002, $t['sB']['per_exec'], 1e-6);
        $this->assertEqualsWithDelta(self::N + 2.0, $t['sB']['e_at_n'], 1e-9);
        $this->assertEqualsWithDelta(1002.0, $t['sB']['e_at_small'], 1e-9);
        $total = 2 * 10000.0 + 3 * (50.0 * 100002 / 1002) + 4 * 20.0 + 5 * (10.0 * 402 / 102) + 6 * 7.0 + 7 * 1.0;
        $this->assertEqualsWithDelta($total, self::route('H2')['rows_examined'], 1e-6);
    }

    public function test_an_unstable_type_uses_the_heaviest_state_of_the_target_side(): void
    {
        // target states ALL 100 / 50 / 400 -> 402 (the high state); the small side is the default state, ALL 100 -> 102
        $d = self::types('H2')['sD'];
        $this->assertSame('ratio', $d['basis']);
        $this->assertEqualsWithDelta(402.0, $d['e_at_n'], 1e-9);
        $this->assertEqualsWithDelta(102.0, $d['e_at_small'], 1e-9);
        $this->assertEqualsWithDelta(10.0 * 402 / 102, $d['per_exec'], 1e-9);
    }

    public function test_h3_uses_the_ratio_for_every_type_that_can_take_it(): void
    {
        $t = self::types('H3');
        // sA: 1,000 x 10,001 / 1,001;  sC: 20 x 9 / 6 = 30
        $this->assertSame('ratio', $t['sA']['basis']);
        $this->assertEqualsWithDelta(1000.0 * 10001 / 1001, $t['sA']['per_exec'], 1e-6);
        $this->assertSame('ratio', $t['sC']['basis']);
        $this->assertEqualsWithDelta(30.0, $t['sC']['per_exec'], 1e-9);
        $total = 2 * (1000.0 * 10001 / 1001) + 3 * (50.0 * 100002 / 1002) + 4 * 30.0 + 5 * (10.0 * 402 / 102) + 6 * 7.0 + 7 * 1.0;
        $this->assertEqualsWithDelta($total, self::route('H3')['rows_examined'], 1e-6);
        $this->assertSame(4, self::route('H3')['ratio_used']); // sA, sB, sC, sD
    }

    public function test_e_small_zero_and_explain_unavailable_fall_back_to_h1_and_are_counted(): void
    {
        foreach (['H2', 'H3'] as $candidate) {
            $t = self::types($candidate);
            $this->assertSame('h1', $t['sE']['basis'], $candidate);
            $this->assertSame('e_small_zero', $t['sE']['fallback'], $candidate);
            $this->assertEqualsWithDelta(7.0, $t['sE']['per_exec'], 1e-9, $candidate); // not 0
            $this->assertSame('h1', $t['sF']['basis'], $candidate);
            $this->assertSame('explain_unavailable:not_explainable_kind: begin', $t['sF']['fallback'], $candidate);
            $this->assertEqualsWithDelta(1.0, $t['sF']['per_exec'], 1e-9, $candidate);
            $totals = RowEstimator::estimate(...self::args($candidate))['totals'];
            $this->assertSame(2, $totals['fell_back_to_h1'], $candidate);
            $this->assertSame(['e_small_zero' => 1, 'explain_unavailable:not_explainable_kind: begin' => 1], $totals['fell_back_by_reason'], $candidate);
            $this->assertSame([1 => 1, 2 => 1, 3 => 0, 4 => 0], $totals['fell_back_by_item'], $candidate);
            $this->assertSame([1 => 1, 2 => 1, 3 => 0, 4 => 0], $totals['routes_with_fall_by_item'], $candidate);
        }
        $this->assertSame(0, RowEstimator::estimate(...self::args('H1'))['totals']['fell_back_to_h1']);
    }

    /** @return array{array<string, mixed>, array<string, mixed>, string} */
    private static function args(string $candidate): array
    {
        [$capture, $explain] = self::fixture();

        return [$capture, $explain, $candidate];
    }

    public function test_a_marked_type_without_an_explain_entry_falls_back_and_is_counted(): void
    {
        [$capture, $explain] = self::fixture();
        $explain['routes'][0]['statements'] = array_values(array_filter($explain['routes'][0]['statements'], static fn (array $s): bool => $s['id'] !== 'sB'));
        $out = RowEstimator::estimate($capture, $explain, 'H2');
        $this->assertSame(1, $out['totals']['fell_back_by_reason']['no_explain_entry']);
    }

    public function test_a_type_that_ran_but_read_nothing_at_a_scale_keeps_the_zero_in_its_series(): void
    {
        [$capture, $explain] = self::fixture();
        $capture['routes'][0]['statement_types'][2]['per_scale']['100']['rows_sum'] = 0; // sC: 0 / 20 / 20 rows per run
        $unread = $capture;
        $unread['routes'][0]['statement_types'][2]['per_scale']['100'] = ['count' => 10, 'rows_sum' => null, 'unread' => 10];
        // with the first scale undefined the series has two points: the value of the largest scale is kept
        $this->assertSame('held_from_largest_scale', RowEstimator::estimate($unread, $explain, 'H1')['routes'][0]['types'][2]['per_exec_h1_basis']);
        $this->assertSame('fit', RowEstimator::estimate($capture, $explain, 'H1')['routes'][0]['types'][2]['per_exec_h1_basis']);
    }

    public function test_an_unmarked_type_takes_the_default_state_and_not_the_heaviest(): void
    {
        [$capture, $explain] = self::fixture();
        // sC is not plan_unstable: a heavier non-default state at N must not be used (H3: 20 x E(N) / E(small) = 20 x 9 / 6)
        $explain['routes'][0]['statements'][2]['target_scale']['high'] = self::state(self::plan('ref', 800));
        $this->assertEqualsWithDelta(30.0, RowEstimator::estimate($capture, $explain, 'H3')['routes'][0]['types'][2]['per_exec'], 1e-9);
    }

    public function test_the_default_state_missing_at_n_falls_back_for_a_type_that_is_not_unstable(): void
    {
        [$capture, $explain] = self::fixture();
        unset($explain['routes'][0]['statements'][2]['target_scale']['default']);
        $explain['routes'][0]['statements'][2]['target_scale']['high'] = self::state(self::plan('ref', 800));
        $out = RowEstimator::estimate($capture, $explain, 'H3');
        $this->assertSame('e_n_not_taken', $out['routes'][0]['types'][2]['fallback']);
        $this->assertSame(1, $out['totals']['fell_back_by_item'][3]);
    }

    public function test_e_at_n_zero_with_e_small_above_zero_is_a_ratio_of_zero_not_a_fall(): void
    {
        [$capture, $explain] = self::fixture();
        $explain['routes'][0]['statements'][1]['target_scale'] = ['default' => self::state(['query_block' => ['message' => 'Impossible WHERE']])]; // sB
        $b = RowEstimator::estimate($capture, $explain, 'H2')['routes'][0]['types'][1];
        $this->assertSame('ratio', $b['basis']);
        $this->assertSame(0.0, $b['per_exec']);
        $this->assertArrayNotHasKey('fallback', $b);
    }

    public function test_a_type_that_did_not_run_at_the_largest_scale_falls_back(): void
    {
        [$capture, $explain] = self::fixture();
        unset($capture['routes'][0]['statement_types'][1]['per_scale']['10000']); // sB
        $out = RowEstimator::estimate($capture, $explain, 'H2');
        $this->assertSame(1, $out['totals']['fell_back_by_reason']['per_exec_measured_undefined']);
    }

    public function test_a_type_seen_at_fewer_than_three_scales_keeps_the_value_of_its_largest_and_is_counted(): void
    {
        [$capture, $explain] = self::fixture();
        unset($capture['routes'][0]['statement_types'][0]['per_scale']['100']); // sA: 100 and 1,000 rows per run at 1,000 and 10,000
        $out = RowEstimator::estimate($capture, $explain, 'H1');
        $a = $out['routes'][0]['types'][0];
        $this->assertSame('held_from_largest_scale', $a['per_exec_h1_basis']);
        $this->assertEqualsWithDelta(1000.0, $a['per_exec'], 1e-9);
        $this->assertSame(1, $out['totals']['per_exec_held']);
    }

    public function test_a_type_with_no_count_formula_or_no_rows_makes_the_route_incomplete_and_is_not_read_as_zero(): void
    {
        [$capture, $explain] = self::fixture();
        $capture['routes'][0]['statement_types'][2]['count_fit'] = ['fit_status' => 'insufficient_scales', 'reason' => 'x'];
        $capture['routes'][0]['statement_types'][3]['per_scale'] = [];
        $route = RowEstimator::estimate($capture, $explain, 'H1')['routes'][0];
        $this->assertFalse($route['complete']);
        $this->assertSame(['count_formula:insufficient_scales', 'no_rows_read_in_capture'], array_column($route['unavailable'], 'reason'));
    }

    public function test_a_route_that_was_only_partly_captured_is_not_complete(): void
    {
        [$capture, $explain] = self::fixture();
        $capture['routes'][0]['status'] = 'partial';
        $this->assertFalse(RowEstimator::estimate($capture, $explain, 'H1')['routes'][0]['complete']);
    }

    public function test_the_route_fit_of_peak_memory_is_carried_when_predictions_are_given(): void
    {
        [$capture, $explain] = self::fixture();
        $predictions = ['routes' => [['route' => 'GET /r', 'quantities' => [
            'peak_memory' => ['status' => 'predicted', 'value' => 12345.0],
            'queries' => ['status' => 'not_predicted', 'fit_status' => 'insufficient_scales', 'reason' => 'two scales'],
        ]]]];
        $route = RowEstimator::estimate($capture, $explain, 'H1', null, $predictions)['routes'][0];
        $this->assertSame(['status' => 'predicted', 'value' => 12345.0], $route['peak_memory']);
        $this->assertSame('not_predicted', $route['queries_route_fit']['status']);
        $this->assertSame('not_given', RowEstimator::estimate($capture, $explain, 'H1')['routes'][0]['peak_memory']['status']);
    }

    public function test_the_same_inputs_give_the_same_output(): void
    {
        [$capture, $explain] = self::fixture();
        $this->assertSame(RowEstimator::estimate($capture, $explain, 'H3'), RowEstimator::estimate($capture, $explain, 'H3'));
    }

    public function test_an_unknown_candidate_is_refused(): void
    {
        [$capture, $explain] = self::fixture();
        $this->expectException(\InvalidArgumentException::class);
        RowEstimator::estimate($capture, $explain, 'H4');
    }
}
