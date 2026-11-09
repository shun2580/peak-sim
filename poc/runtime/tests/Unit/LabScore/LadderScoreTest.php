<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabScore;

use PeakSim\Lab\Score\LadderScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../lab/score/LadderScore.php';

/**
 * The scoring rules of the lab backtest (simulator-spec.md 7.2 and 7.4 B), held on made-up data: every edge of a line, the
 * three ways a limit rps is measured, and what is indeterminate.
 */
final class LadderScoreTest extends TestCase
{
    /** A stage: observed null = not measured. The prediction defaults to the observation. */
    private static function stage(float $rps, ?float $observed, bool $valid = true, ?float $point = null, ?float $safe = null, ?float $naive = null): array
    {
        $base = $observed ?? 0.5;

        return [
            'rps' => $rps,
            'predicted' => ['point' => $point ?? $base, 'safe' => $safe ?? $point ?? $base, 'naive' => $naive ?? $base],
            'measured' => $observed === null ? null : ['valid' => $valid, 'utilization' => $observed],
        ];
    }

    /** @param list<array> $stages */
    private static function scenario(string $id, array $stages, ?float $limitPoint = null, ?float $limitSafe = null, ?float $limitNaive = null): array
    {
        return [
            'id' => $id,
            'stages' => $stages,
            'predicted_limit_rps' => ['point' => $limitPoint, 'safe' => $limitSafe, 'naive' => $limitNaive],
        ];
    }

    /** @param list<array> $scenarios */
    private static function input(array $scenarios, float $threshold = 0.8): array
    {
        return ['threshold' => $threshold, 'scenarios' => $scenarios];
    }

    /**
     * A scenario of up to four stages that are all usable: observed 0.20 0.40 0.60 0.85 at rps 1 2 3 4. With four stages the limit rps
     * is measured by interpolation between (3, 0.60) and (4, 0.85): 3.8. With two or three it is extended from the top two stages (the stage that reached the threshold is added, invalid).
     */
    private static function standard(string $id, int $n = 4, float $offset = 0.0, float $naiveOffset = 0.3, ?float $limit = 3.8): array
    {
        $obs = [0.20, 0.40, 0.60, 0.85];
        $stages = [];
        for ($i = 0; $i < $n; $i++) {
            $stages[] = self::stage($i + 1.0, $obs[$i], true, $obs[$i] + $offset, $obs[$i] + $offset + 0.05, $obs[$i] + $naiveOffset);
        }
        if ($n < 4) {
            // The stage that reached the threshold came out invalid: the limit is then extended from the top two valid stages.
            $stages[] = self::stage($n + 1.0, 0.95, false);
        }

        return self::scenario($id, $stages, $limit, $limit, $limit === null ? null : $limit * 2);
    }

    /** @return list<array> four scenarios: 16 usable stages, 4 limits */
    private static function four(float $offset = 0.0, float $naiveOffset = 0.3, ?float $limit = 3.8): array
    {
        return [
            self::standard('a', 4, $offset, $naiveOffset, $limit),
            self::standard('b', 4, $offset, $naiveOffset, $limit),
            self::standard('c', 4, $offset, $naiveOffset, $limit),
            self::standard('d', 4, $offset, $naiveOffset, $limit),
        ];
    }

    // ---- usable stages -----------------------------------------------------------------------------------------------

    public function test_a_stage_is_usable_from_ten_to_ninety_percent_both_ends_in(): void
    {
        $stages = [
            self::stage(1.0, 0.0999),
            self::stage(2.0, 0.10),
            self::stage(3.0, 0.50),
            self::stage(4.0, 0.90),
            self::stage(5.0, 0.9001),
        ];
        $r = LadderScore::score(self::input([self::scenario('s', $stages)]));
        $usable = array_map(static fn (array $s): bool => $s['usable'], $r['scenarios'][0]['stages']);
        $this->assertSame([false, true, true, true, false], $usable);
        $this->assertSame(3, $r['counts']['usable_stages']);
    }

    public function test_an_invalid_stage_and_a_stage_that_was_not_measured_are_never_usable(): void
    {
        $r = LadderScore::score(self::input([self::scenario('s', [
            self::stage(1.0, 0.5, false),
            self::stage(2.0, null),
            self::stage(3.0, 0.5),
        ])]));
        $this->assertSame(1, $r['counts']['usable_stages']);
        $this->assertSame(2, $r['counts']['stages_measured']);
        $this->assertSame(1, $r['counts']['valid_stages']);
        $this->assertNull($r['scenarios'][0]['stages'][1]['observed']);
    }

    public function test_stages_given_out_of_order_are_put_in_order_of_rps(): void
    {
        $r = LadderScore::score(self::input([self::scenario('s', [self::stage(3.0, 0.6), self::stage(1.0, 0.2), self::stage(2.0, 0.4)])]));
        $this->assertSame([1.0, 2.0, 3.0], array_column($r['scenarios'][0]['stages'], 'rps'));
    }

    // ---- the mean difference (items 1 and 2) -------------------------------------------------------------------------

    public function test_the_mean_difference_is_over_the_usable_stages_only(): void
    {
        // usable: obs 0.50 predicted 0.60 (10 pt). Not usable: obs 0.95 predicted 0.50 (45 pt, out of range), and an invalid stage.
        $r = LadderScore::score(self::input([self::scenario('s', [
            self::stage(1.0, 0.50, true, 0.60),
            self::stage(2.0, 0.95, true, 0.50),
            self::stage(3.0, 0.50, false, 0.99),
        ])]));
        $this->assertEqualsWithDelta(10.0, $r['items']['2']['mean_points'], 1e-9);
    }

    public function test_item_2_lines_are_15_and_5_points_each_end_in_and_just_past_is_out(): void
    {
        $at = static fn (float $offset): array => LadderScore::score(self::input(self::four($offset)))['items']['2'];
        $this->assertTrue($at(0.15)['minimum']);
        $this->assertFalse($at(0.1501)['minimum']);
        $this->assertTrue($at(0.05)['target']);
        $this->assertTrue($at(0.05)['minimum']);
        $this->assertFalse($at(0.0501)['target']);
        $this->assertTrue($at(0.0501)['minimum']);
    }

    public function test_the_difference_is_the_absolute_value_so_under_and_over_do_not_cancel(): void
    {
        $r = LadderScore::score(self::input([self::scenario('s', [
            self::stage(1.0, 0.50, true, 0.60),
            self::stage(2.0, 0.50, true, 0.40),
        ])]));
        $this->assertEqualsWithDelta(10.0, $r['items']['2']['mean_points'], 1e-9);
        $this->assertEqualsWithDelta(10.0, $r['scenarios'][0]['stages'][0]['difference_points']['point'], 1e-9);
        $this->assertEqualsWithDelta(-10.0, $r['scenarios'][0]['stages'][1]['difference_points']['point'], 1e-9);
    }

    public function test_item_1_needs_the_prediction_strictly_closer_than_the_naive_estimate(): void
    {
        $item = static fn (float $offset): array => LadderScore::score(self::input(self::four($offset, 0.1)))['items']['1'];
        $this->assertTrue($item(0.05)['minimum']);
        $this->assertFalse($item(0.1)['minimum'], 'the same mean is not smaller');
        $this->assertFalse($item(0.2)['minimum']);
        $this->assertSame($item(0.05)['minimum'], $item(0.05)['target'], 'item 1 is the same line at both levels');
        $this->assertEqualsWithDelta(10.0, $item(0.05)['mean_points_naive'], 1e-9);
    }

    // ---- "exceeded the threshold" (item 3) ---------------------------------------------------------------------------

    /** One scenario of ten valid usable stages at 20%; $disagree of them are predicted at 90% (over the threshold). */
    private static function agreement(int $disagree): array
    {
        $stages = [];
        for ($i = 1; $i <= 10; $i++) {
            $stages[] = self::stage((float) $i, 0.20, true, $i <= $disagree ? 0.90 : 0.20);
        }

        return self::input([self::scenario('s', $stages)]);
    }

    public function test_item_3_is_80_percent_with_both_ends_exact(): void
    {
        $this->assertTrue(LadderScore::score(self::agreement(2))['items']['3']['minimum'], '8 of 10');
        $this->assertFalse(LadderScore::score(self::agreement(3))['items']['3']['minimum'], '7 of 10');
        $this->assertTrue(LadderScore::score(self::agreement(0))['items']['3']['minimum']);
        $r = LadderScore::score(self::agreement(2));
        $this->assertSame(8, $r['items']['3']['agree']);
        $this->assertSame(10, $r['items']['3']['valid_stages']);
        $this->assertEqualsWithDelta(0.8, $r['items']['3']['share'], 1e-12);
        $this->assertSame($r['items']['3']['minimum'], $r['items']['3']['target'], 'the line is the same at both levels');
    }

    public function test_item_3_counts_all_valid_stages_not_only_the_usable_ones_and_leaves_out_the_invalid(): void
    {
        $r = LadderScore::score(self::input([self::scenario('s', [
            self::stage(1.0, 0.95, true, 0.95),    // valid, not usable (over 90%), agrees: counted in item 3
            self::stage(2.0, 0.95, true, 0.20),    // valid, not usable, disagrees: counted
            self::stage(3.0, 0.95, false, 0.20),   // invalid: left out
            self::stage(4.0, null),                // not measured: left out
        ])]));
        $this->assertSame(2, $r['items']['3']['valid_stages']);
        $this->assertSame(1, $r['items']['3']['agree']);
        $this->assertSame(0, $r['counts']['usable_stages']);
    }

    public function test_exceeding_the_threshold_is_strictly_above_it(): void
    {
        $r = LadderScore::score(self::input([self::scenario('s', [
            self::stage(1.0, 0.80, true, 0.80),    // neither exceeds: agree
            self::stage(2.0, 0.80, true, 0.8001),  // predicted exceeds, observed does not: disagree
            self::stage(3.0, 0.8001, true, 0.80),  // observed exceeds, predicted does not: disagree
        ])]));
        $this->assertSame([true, false, false], array_column(array_column($r['scenarios'][0]['stages'], 'agree'), 'point'));
        $this->assertSame([false, false, true], array_column(array_column($r['scenarios'][0]['stages'], 'over_threshold'), 'observed'));
    }

    // ---- the measured limit rps --------------------------------------------------------------------------------------

    private static function measuredLimit(array $stages): array
    {
        return LadderScore::score(self::input([self::scenario('s', $stages)]))['scenarios'][0]['limit']['measured'];
    }

    public function test_the_limit_is_interpolated_between_the_valid_stages_that_straddle_the_threshold(): void
    {
        $m = self::measuredLimit([self::stage(1.0, 0.5), self::stage(2.0, 0.7), self::stage(3.0, 0.9)]);
        $this->assertSame('measured', $m['status']);
        $this->assertSame('interpolated', $m['method']);
        $this->assertEqualsWithDelta(2.5, $m['rps'], 1e-9);
        $this->assertSame([2.0, 3.0], array_column($m['basis'], 'rps'));
    }

    public function test_a_stage_at_exactly_the_threshold_reaches_it(): void
    {
        $m = self::measuredLimit([self::stage(1.0, 0.6), self::stage(2.0, 0.8)]);
        $this->assertSame('interpolated', $m['method']);
        $this->assertEqualsWithDelta(2.0, $m['rps'], 1e-9);
    }

    public function test_an_invalid_stage_in_between_is_skipped_when_straddling(): void
    {
        $m = self::measuredLimit([self::stage(1.0, 0.5), self::stage(2.0, 0.6, false), self::stage(3.0, 0.9)]);
        $this->assertSame('interpolated', $m['method']);
        $this->assertEqualsWithDelta(1.0 + (0.3 / 0.4) * 2.0, $m['rps'], 1e-9);
    }

    public function test_when_a_stage_at_the_threshold_was_invalid_the_two_largest_valid_stages_are_extended_and_marked(): void
    {
        // (1, 0.40) (2, 0.60) valid; the stage that reached the threshold (3, 0.90) is invalid. Slope 0.2 per rps: 0.8 at rps 3.
        $m = self::measuredLimit([self::stage(1.0, 0.4), self::stage(2.0, 0.6), self::stage(3.0, 0.9, false)]);
        $this->assertSame('measured', $m['status']);
        $this->assertSame('extended', $m['method']);
        $this->assertEqualsWithDelta(3.0, $m['rps'], 1e-9);
        $this->assertSame([1.0, 2.0], array_column($m['basis'], 'rps'));
    }

    public function test_the_extension_uses_the_two_largest_rps_not_the_first_two(): void
    {
        // Valid (1, 0.1) (2, 0.2) (3, 0.4) (4, 0.6); the stage at the threshold (5, 0.85) is invalid. The line of the first two would
        // reach 0.8 at rps 8; the line of the largest two (3, 0.4) (4, 0.6) reaches it at rps 5.
        $m = self::measuredLimit([self::stage(1.0, 0.1), self::stage(2.0, 0.2), self::stage(3.0, 0.4), self::stage(4.0, 0.6), self::stage(5.0, 0.85, false)]);
        $this->assertSame('extended', $m['method']);
        $this->assertSame([3.0, 4.0], array_column($m['basis'], 'rps'));
        $this->assertEqualsWithDelta(5.0, $m['rps'], 1e-9);
    }

    public function test_a_valid_straddle_is_interpolated_even_if_a_later_stage_at_the_threshold_was_invalid(): void
    {
        $m = self::measuredLimit([self::stage(1.0, 0.5), self::stage(2.0, 0.9), self::stage(3.0, 0.95, false)]);
        $this->assertSame('interpolated', $m['method']);
    }

    public function test_a_ladder_that_stopped_below_the_threshold_before_the_top_stage_is_not_measured_as_app_bound(): void
    {
        // The application became the bottleneck: the last measured stage came out invalid at 60%, and the stages above were not measured.
        $m = self::measuredLimit([self::stage(1.0, 0.2), self::stage(2.0, 0.4), self::stage(3.0, 0.6, false), self::stage(4.0, null), self::stage(5.0, null)]);
        $this->assertSame('not_measured', $m['status']);
        $this->assertNull($m['rps']);
        $this->assertSame('app_bound_below_threshold', $m['reason_code']);
        $this->assertStringContainsString('app_bound_below_threshold', $m['reason']);
        // The same when the last measured stage is valid and below the threshold.
        $m = self::measuredLimit([self::stage(1.0, 0.2), self::stage(2.0, 0.4), self::stage(3.0, 0.6), self::stage(4.0, null)]);
        $this->assertSame('app_bound_below_threshold', $m['reason_code']);
    }

    public function test_a_ladder_that_reached_the_top_stage_without_reaching_the_threshold_is_not_measured_as_never_reached(): void
    {
        $m = self::measuredLimit([self::stage(1.0, 0.1), self::stage(2.0, 0.2), self::stage(3.0, 0.3)]);
        $this->assertSame('not_measured', $m['status']);
        $this->assertNull($m['rps']);
        $this->assertSame('never_reached_threshold', $m['reason_code']);
        $this->assertStringContainsString('never_reached_threshold', $m['reason']);
    }

    public function test_the_three_not_measured_reasons_are_told_apart(): void
    {
        $few = self::measuredLimit([self::stage(1.0, 0.5), self::stage(2.0, 0.85, false)]);
        $app = self::measuredLimit([self::stage(1.0, 0.2), self::stage(2.0, 0.4), self::stage(3.0, null)]);
        $never = self::measuredLimit([self::stage(1.0, 0.2), self::stage(2.0, 0.4)]);
        $this->assertSame(['fewer_than_two_valid', 'app_bound_below_threshold', 'never_reached_threshold'], [$few['reason_code'], $app['reason_code'], $never['reason_code']]);
    }

    public function test_fewer_than_two_valid_stages_is_not_measured(): void
    {
        foreach ([[], [self::stage(1.0, 0.5)], [self::stage(1.0, 0.5), self::stage(2.0, 0.85, false)], [self::stage(1.0, 0.5), self::stage(2.0, null)]] as $stages) {
            $m = self::measuredLimit($stages);
            $this->assertSame('not_measured', $m['status']);
            $this->assertNull($m['rps']);
            $this->assertStringContainsString('fewer than two valid stages', $m['reason']);
        }
    }

    public function test_a_line_that_does_not_rise_never_reaches_the_threshold_so_it_is_not_measured(): void
    {
        foreach ([[0.5, 0.5], [0.6, 0.5]] as [$a, $b]) {
            $m = self::measuredLimit([self::stage(1.0, $a), self::stage(2.0, $b), self::stage(3.0, 0.85, false)]);
            $this->assertSame('not_measured', $m['status']);
            $this->assertStringContainsString('does not rise', $m['reason']);
        }
    }

    public function test_when_every_valid_stage_is_at_or_above_the_threshold_and_none_was_invalid_there_it_is_not_measured(): void
    {
        // Nothing straddles the threshold, and the spec extends a line only after an invalid stage at the threshold.
        $m = self::measuredLimit([self::stage(1.0, 0.85), self::stage(2.0, 0.95)]);
        $this->assertSame('not_measured', $m['status']);
        $this->assertNull($m['rps']);
        $this->assertSame('no_valid_stage_below_threshold', $m['reason_code']);
    }

    public function test_an_extension_that_lands_at_zero_or_less_is_not_measured(): void
    {
        $m = self::measuredLimit([self::stage(1.0, 0.9), self::stage(2.0, 0.95), self::stage(3.0, 0.99, false)]);
        $this->assertSame('not_measured', $m['status']);
        $this->assertStringContainsString('0 or less', $m['reason']);
    }

    // ---- item 4: the limit rps ---------------------------------------------------------------------------------------

    /** Five scenarios whose limit rps is measured at 2.5 (interpolated); the point predictions are the given ones. */
    private static function limits(array $points, array $safe = [], array $naive = []): array
    {
        $scenarios = [];
        foreach ($points as $i => $p) {
            $scenarios[] = self::scenario('s' . $i, [self::stage(1.0, 0.5), self::stage(2.0, 0.7), self::stage(3.0, 0.9)], $p, $safe[$i] ?? null, $naive[$i] ?? null);
        }

        return LadderScore::score(self::input($scenarios));
    }

    public function test_item_4_tolerances_are_exactly_20_and_10_percent_each_end_in_and_just_past_is_out(): void
    {
        // measured 2.5: +20% = 3.0, -20% = 2.0, +10% = 2.75, -10% = 2.25
        $r = self::limits([3.0, 2.0, 2.75, 2.25, 4.0])['items']['4'];
        $this->assertSame(5, $r['scenarios_with_limit']);
        $this->assertSame(4, $r['within_minimum']);
        $this->assertSame(2, $r['within_target']);
        $just = self::limits([3.0001, 1.9999, 2.7501, 2.2499, 2.5])['items']['4'];
        $this->assertSame(3, $just['within_minimum'], 'only the two just past the 20% edges are out');
        $this->assertSame(1, $just['within_target']);
    }

    public function test_item_4_needs_80_percent_of_the_scenarios_with_a_measured_limit(): void
    {
        $this->assertTrue(self::limits([2.5, 2.5, 2.5, 2.5, 9.0])['items']['4']['minimum'], '4 of 5');
        $this->assertFalse(self::limits([2.5, 2.5, 2.5, 9.0, 9.0])['items']['4']['minimum'], '3 of 5');
        $this->assertTrue(self::limits([2.5, 2.5, 2.5, 2.5])['items']['4']['target']);
        $this->assertFalse(self::limits([2.5, 2.5, 2.5, 9.0])['items']['4']['minimum'], '3 of 4');
    }

    public function test_the_two_levels_of_item_4_are_judged_apart(): void
    {
        // four scenarios, all at +15%: inside 20%, outside 10%
        $r = self::limits([2.875, 2.875, 2.875, 2.875])['items']['4'];
        $this->assertTrue($r['minimum']);
        $this->assertFalse($r['target']);
    }

    public function test_item_4_leaves_out_the_scenarios_whose_limit_was_not_measured(): void
    {
        $scenarios = [];
        foreach ([2.5, 2.5, 2.5, 2.5] as $i => $p) {
            $scenarios[] = self::scenario('s' . $i, [self::stage(1.0, 0.5), self::stage(2.0, 0.7), self::stage(3.0, 0.9)], $p);
        }
        $scenarios[] = self::scenario('lonely', [self::stage(1.0, 0.5)], 99.0);   // not measured, so not in the share
        $r = LadderScore::score(self::input($scenarios));
        $this->assertSame(4, $r['items']['4']['scenarios_with_limit']);
        $this->assertTrue($r['items']['4']['minimum']);
        $this->assertSame('not_measured', $r['scenarios'][4]['limit']['measured']['status']);
    }

    public function test_a_predicted_limit_of_null_is_a_miss_and_the_naive_and_safe_values_are_kept_apart(): void
    {
        $r = self::limits([null, 2.5, 2.5, 2.5], [2.5, 2.5, 2.5, 2.5], [9.0, 9.0, 9.0, 9.0]);
        $item = $r['items']['4'];
        $this->assertSame(3, $item['within_minimum']);
        $this->assertFalse($item['minimum']);
        $this->assertSame(4, $item['safe_within_minimum']);
        $this->assertSame(0, $item['naive_within_minimum']);
        $this->assertFalse($item['naive_used_for_the_verdict']);
        $this->assertNull($r['scenarios'][0]['limit']['relative_error']['point']);
        $this->assertEqualsWithDelta(2.5 / 2.5 - 1.0, $r['scenarios'][1]['limit']['relative_error']['point'], 1e-9);
    }

    public function test_the_naive_limit_never_changes_the_verdict(): void
    {
        $good = LadderScore::score(self::input(self::four()));
        $bad = self::four();
        foreach ($bad as $i => $s) {
            $bad[$i]['predicted_limit_rps']['naive'] = 1000.0;
        }
        $this->assertSame($good['verdict'], LadderScore::score(self::input($bad))['verdict']);
    }

    // ---- the safe side -----------------------------------------------------------------------------------------------

    public function test_every_stage_where_the_safe_prediction_is_below_the_observation_is_listed(): void
    {
        $r = LadderScore::score(self::input([
            self::scenario('x', [
                self::stage(1.0, 0.50, true, 0.5, 0.45),    // below: listed
                self::stage(2.0, 0.50, true, 0.5, 0.50),    // equal: not below
                self::stage(3.0, 0.50, false, 0.5, 0.40),   // invalid but measured: listed, marked invalid
                self::stage(4.0, null, true, 0.5, 0.0),     // not measured: nothing to compare
            ]),
            self::scenario('y', [self::stage(1.0, 0.95, true, 0.9, 0.90)]),   // below and not usable: listed
        ]));
        $list = $r['safe_side_below_observed'];
        $this->assertCount(3, $list);
        $this->assertSame(['x', 1.0, true, true], [$list[0]['scenario'], $list[0]['rps'], $list[0]['valid'], $list[0]['usable']]);
        $this->assertSame(['x', 3.0, false, false], [$list[1]['scenario'], $list[1]['rps'], $list[1]['valid'], $list[1]['usable']]);
        $this->assertSame(['y', 1.0, true, false], [$list[2]['scenario'], $list[2]['rps'], $list[2]['valid'], $list[2]['usable']]);
        $this->assertEqualsWithDelta(0.45, $list[0]['safe'], 1e-12);
        $this->assertEqualsWithDelta(0.50, $list[0]['observed'], 1e-12);
    }

    public function test_when_the_safe_side_is_never_below_the_list_is_empty(): void
    {
        $this->assertSame([], LadderScore::score(self::input(self::four()))['safe_side_below_observed']);
    }

    // ---- indeterminate and the verdict -------------------------------------------------------------------------------

    /** @param list<int> $stagesPerScenario */
    private static function shaped(array $stagesPerScenario): array
    {
        $scenarios = [];
        foreach ($stagesPerScenario as $i => $n) {
            $scenarios[] = self::standard('s' . $i, $n);
        }

        return LadderScore::score(self::input($scenarios));
    }

    public function test_exactly_ten_usable_stages_is_determinate_and_nine_is_indeterminate(): void
    {
        $ten = self::shaped([3, 3, 2, 2]);
        $this->assertSame(10, $ten['counts']['usable_stages']);
        $this->assertSame(4, $ten['counts']['scenarios_with_limit']);
        $this->assertFalse($ten['indeterminate']['is']);
        $this->assertNotSame('indeterminate', $ten['verdict']);

        $nine = self::shaped([3, 2, 2, 2]);
        $this->assertSame(9, $nine['counts']['usable_stages']);
        $this->assertSame(4, $nine['counts']['scenarios_with_limit']);
        $this->assertTrue($nine['indeterminate']['is']);
        $this->assertSame('indeterminate', $nine['verdict']);
        $this->assertSame(['usable stages 9 < 10'], $nine['indeterminate']['reasons']);
    }

    public function test_exactly_four_scenarios_with_a_measured_limit_is_determinate_and_three_is_indeterminate(): void
    {
        $four = self::shaped([4, 4, 4, 4]);
        $this->assertSame(4, $four['counts']['scenarios_with_limit']);
        $this->assertFalse($four['indeterminate']['is']);

        $withLonely = self::shaped([4, 4, 4, 4, 1]);
        $this->assertSame(4, $withLonely['counts']['scenarios_with_limit'], 'the fifth has one stage: not measured, the four are still four');
        $this->assertFalse($withLonely['indeterminate']['is']);

        $short = self::shaped([4, 4, 4, 1]);
        $this->assertSame(3, $short['counts']['scenarios_with_limit']);
        $this->assertSame(13, $short['counts']['usable_stages']);
        $this->assertTrue($short['indeterminate']['is']);
        $this->assertSame('indeterminate', $short['verdict']);
        $this->assertSame(['scenarios whose limit rps was measured 3 < 4'], $short['indeterminate']['reasons']);
    }

    public function test_both_reasons_are_given_when_both_hold_and_the_items_are_still_computed(): void
    {
        $r = self::shaped([3, 3, 1]);
        $this->assertCount(2, $r['indeterminate']['reasons']);
        $this->assertSame('indeterminate', $r['verdict']);
        $this->assertNotNull($r['items']['2']['mean_points']);
    }

    public function test_an_input_with_nothing_measured_is_indeterminate_and_does_not_divide_by_zero(): void
    {
        $r = LadderScore::score(self::input([self::scenario('s', [self::stage(1.0, null), self::stage(2.0, null)])]));
        $this->assertSame('indeterminate', $r['verdict']);
        $this->assertNull($r['items']['2']['mean_points']);
        $this->assertFalse($r['items']['1']['minimum']);
        $this->assertFalse($r['items']['2']['minimum']);
        $this->assertFalse($r['items']['3']['minimum']);
        $this->assertFalse($r['items']['4']['minimum']);
        $this->assertNull($r['items']['3']['share']);
    }

    public function test_pass_at_the_target_when_every_item_is_met_at_both_levels(): void
    {
        $r = LadderScore::score(self::input(self::four(0.02)));
        $this->assertSame('pass_target', $r['verdict']);
        $this->assertTrue($r['items']['1']['target'] && $r['items']['2']['target'] && $r['items']['3']['target'] && $r['items']['4']['target']);
    }

    public function test_pass_at_the_minimum_when_only_the_target_difference_is_missed(): void
    {
        $this->assertSame('pass_minimum', LadderScore::score(self::input(self::four(0.10)))['verdict']);
        $this->assertSame('pass_minimum', LadderScore::score(self::input(self::four(0.02, 0.3, 3.8 * 1.15)))['verdict'], 'limit at +15%: minimum only');
    }

    public function test_fail_when_one_item_misses_the_minimum(): void
    {
        $this->assertSame('fail', LadderScore::score(self::input(self::four(0.20)))['verdict'], 'mean difference 20 points');
        $this->assertSame('fail', LadderScore::score(self::input(self::four(0.02, 0.3, 9.0)))['verdict'], 'limit far off');
        $this->assertSame('fail', LadderScore::score(self::input(self::four(0.02, 0.01)))['verdict'], 'not better than the naive estimate');
    }

    public function test_indeterminate_is_never_a_pass_even_when_every_item_would_pass(): void
    {
        $r = LadderScore::score(self::input([self::standard('a', 4, 0.0), self::standard('b', 4, 0.0), self::standard('c', 4, 0.0)]));
        $this->assertTrue($r['items']['1']['minimum'] && $r['items']['2']['target'] && $r['items']['3']['minimum'] && $r['items']['4']['target']);
        $this->assertSame('indeterminate', $r['verdict']);
    }

    // ---- the input ---------------------------------------------------------------------------------------------------

    #[DataProvider('badInputs')]
    public function test_an_input_that_is_not_in_the_form_is_refused(callable $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LadderScore::score($make());
    }

    /** @return array<string, array{callable}> */
    public static function badInputs(): array
    {
        $ok = static fn (): array => self::input(self::four());

        return [
            'no threshold' => [static fn (): array => ['scenarios' => $ok()['scenarios']]],
            'threshold 1' => [static fn (): array => self::input(self::four(), 1.0)],
            'threshold 0' => [static fn (): array => self::input(self::four(), 0.0)],
            'no scenarios' => [static fn (): array => self::input([])],
            'duplicate scenario id' => [static fn (): array => self::input([self::standard('a'), self::standard('a')])],
            'duplicate rps' => [static fn (): array => self::input([self::scenario('a', [self::stage(1.0, 0.5), self::stage(1.0, 0.6)])])],
            'rps 0' => [static fn (): array => self::input([self::scenario('a', [self::stage(0.0, 0.5)])])],
            'negative observed' => [static fn (): array => self::input([self::scenario('a', [self::stage(1.0, -0.1)])])],
            'string prediction' => [static function (): array {
                $s = self::stage(1.0, 0.5);
                $s['predicted']['point'] = '0.5';

                return self::input([self::scenario('a', [$s])]);
            }],
            'no valid flag' => [static function (): array {
                $s = self::stage(1.0, 0.5);
                unset($s['measured']['valid']);

                return self::input([self::scenario('a', [$s])]);
            }],
            'no predicted limit' => [static function (): array {
                $s = self::scenario('a', [self::stage(1.0, 0.5)]);
                unset($s['predicted_limit_rps']);

                return self::input([$s]);
            }],
            'predicted limit 0' => [static fn (): array => self::input([self::scenario('a', [self::stage(1.0, 0.5)], 0.0)])],
            'scenario without id' => [static fn (): array => self::input([['stages' => []]])],
        ];
    }
}
