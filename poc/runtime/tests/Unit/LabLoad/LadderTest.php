<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\Ladder;
use PeakSim\Runtime\LabLoad\RouteMix;
use PeakSim\Runtime\LabLoad\ScenarioPlan;
use PHPUnit\Framework\TestCase;

final class LadderTest extends TestCase
{
    /** @return list<string> */
    private static function routes(): array
    {
        return array_map(static fn (int $i): string => "GET /r$i", range(1, 8));
    }

    public function test_the_steps_are_the_13_of_the_spec_in_ascending_order(): void
    {
        $this->assertSame([0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0], Ladder::RUNGS);
        $this->assertSame(360, Ladder::DURATION_SEC);
        $this->assertSame(60, Ladder::WARMUP_SEC);
    }

    public function test_each_scenario_has_the_count_and_the_mix_of_the_spec_table(): void
    {
        $this->assertSame(['id' => 't-1', 'scale' => 30000, 'multiple' => 3, 'mix' => RouteMix::EVEN], Ladder::scenario('t-1'));
        $this->assertSame(['id' => 't-2', 'scale' => 100000, 'multiple' => 10, 'mix' => RouteMix::EVEN], Ladder::scenario('t-2'));
        $this->assertSame(['id' => 't-3', 'scale' => 100000, 'multiple' => 10, 'mix' => RouteMix::FIRST_80], Ladder::scenario('t-3'));
        $this->assertSame(['id' => 't-4', 'scale' => 10000, 'multiple' => 1, 'mix' => RouteMix::EVEN], Ladder::scenario('t-4'));
        $this->assertSame(['id' => 't-5', 'scale' => 100000, 'multiple' => 10, 'mix' => RouteMix::LAST_80], Ladder::scenario('t-5'));
    }

    public function test_an_unknown_scenario_is_refused_and_so_is_a_calibration_one(): void
    {
        foreach (['t-6', 'cal-1', '', 'T-1'] as $id) {
            try {
                Ladder::scenario($id);
                $this->fail("expected a refusal for '$id'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_trial_is_a_calibration_mix_at_n0_and_never_a_prediction_scenario(): void
    {
        $this->assertSame(['id' => 'trial-cal-2', 'scale' => 10000, 'multiple' => 1, 'mix' => RouteMix::FIRST_80, 'trial' => true], Ladder::trial('cal-2'));
        $this->assertSame(['id' => 'trial-cal-3', 'scale' => 10000, 'multiple' => 1, 'mix' => RouteMix::ODD_80, 'trial' => true], Ladder::trial('cal-3'));
        $this->assertSame(1.0, Ladder::TRIAL_RPS);
        // t-4 (N_0, even), the other scenarios, cal-1 and the bare `even` are refused: a trial must never measure a prediction scenario
        foreach (['t-1', 't-2', 't-3', 't-4', 't-5', 'even', 'first80', 'cal-1', 'cal-2 ', '', 'trial-cal-2'] as $name) {
            try {
                Ladder::trial($name);
                $this->fail("expected a refusal for trial '$name'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // and a trial name is no prediction scenario
        $this->expectException(\InvalidArgumentException::class);
        Ladder::scenario('trial-cal-2');
    }

    public function test_a_trial_never_has_the_mix_and_count_of_a_prediction_scenario(): void
    {
        $predictions = array_map(static fn (string $id): array => [Ladder::scenario($id)['scale'], Ladder::scenario($id)['mix']], ['t-1', 't-2', 't-3', 't-4', 't-5']);
        foreach (['cal-2', 'cal-3'] as $name) {
            $t = Ladder::trial($name);
            $this->assertNotContains([$t['scale'], $t['mix']], $predictions);
        }
    }

    public function test_the_measurement_goes_by_scale_n0_then_3_n0_then_10_n0(): void
    {
        $this->assertSame([
            ['scale' => 10000, 'scenarios' => ['t-4']],
            ['scale' => 30000, 'scenarios' => ['t-1']],
            ['scale' => 100000, 'scenarios' => ['t-2', 't-3', 't-5']],
        ], Ladder::order());
    }

    public function test_the_recheck_scenarios_have_the_count_and_the_mix_of_section_7_4_1(): void
    {
        $this->assertSame(['id' => 'r-1', 'scale' => 50000, 'multiple' => 5, 'mix' => RouteMix::EVEN], Ladder::scenario('r-1'));
        $this->assertSame(['id' => 'r-2', 'scale' => 50000, 'multiple' => 5, 'mix' => RouteMix::FIRST_80], Ladder::scenario('r-2'));
        $this->assertSame(['id' => 'r-3', 'scale' => 200000, 'multiple' => 20, 'mix' => RouteMix::EVEN], Ladder::scenario('r-3'));
        $this->assertSame(['id' => 'r-4', 'scale' => 200000, 'multiple' => 20, 'mix' => RouteMix::FIRST_80], Ladder::scenario('r-4'));
        $this->assertSame(['id' => 'r-5', 'scale' => 200000, 'multiple' => 20, 'mix' => RouteMix::LAST_80], Ladder::scenario('r-5'));
    }

    public function test_the_recheck_goes_by_scale_5_n0_then_20_n0_and_never_mixes_with_the_first_set(): void
    {
        $this->assertSame([
            ['scale' => 50000, 'scenarios' => ['r-1', 'r-2']],
            ['scale' => 200000, 'scenarios' => ['r-3', 'r-4', 'r-5']],
        ], Ladder::order(Ladder::SET_RECHECK));
        // the first set is what it was, whether it is asked for by default or by name
        $this->assertSame(Ladder::order(), Ladder::order(Ladder::SET_FIRST));
        foreach (Ladder::order(Ladder::SET_FIRST) as $group) {
            foreach ($group['scenarios'] as $id) {
                $this->assertStringStartsWith('t-', $id);
            }
        }
    }

    public function test_an_unknown_scenario_set_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ladder::order('x');
    }

    public function test_a_trial_is_never_a_recheck_scenario_either(): void
    {
        foreach (['r-1', 'r-2', 'r-3', 'r-4', 'r-5'] as $id) {
            try {
                Ladder::trial($id);
                $this->fail("expected a refusal for trial '$id'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_third_backtest_scenarios_have_the_count_and_the_mix_of_section_7_4_2(): void
    {
        $this->assertSame(['id' => 's-1', 'scale' => 300000, 'multiple' => 30, 'mix' => RouteMix::EVEN], Ladder::scenario('s-1'));
        $this->assertSame(['id' => 's-2', 'scale' => 300000, 'multiple' => 30, 'mix' => RouteMix::FIRST_80], Ladder::scenario('s-2'));
        $this->assertSame(['id' => 's-3', 'scale' => 300000, 'multiple' => 30, 'mix' => RouteMix::LAST_80], Ladder::scenario('s-3'));
        $this->assertSame(['id' => 's-4', 'scale' => 300000, 'multiple' => 30, 'mix' => RouteMix::ODD_80], Ladder::scenario('s-4'));
        $this->assertSame(['id' => 's-5', 'scale' => 300000, 'multiple' => 30, 'mix' => RouteMix::EVEN_80], Ladder::scenario('s-5'));
        $this->assertSame([['scale' => 300000, 'scenarios' => ['s-1', 's-2', 's-3', 's-4', 's-5']]], Ladder::order(Ladder::SET_THIRD));
    }

    public function test_the_third_backtest_ladder_is_the_13_steps_with_0_025_and_0_035_under_the_lowest(): void
    {
        $this->assertSame([0.025, 0.035, 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0], Ladder::RUNGS_S);
        $this->assertSame(array_merge([0.025, 0.035], Ladder::RUNGS), Ladder::RUNGS_S);
        $this->assertCount(15, Ladder::RUNGS_S);
        foreach (['s-1', 's-2', 's-3', 's-4', 's-5'] as $id) {
            $this->assertSame(Ladder::RUNGS_S, Ladder::rungsFor($id), $id);
        }
    }

    public function test_the_first_two_sets_keep_their_13_steps_their_scenarios_and_their_order_to_the_bit(): void
    {
        // a pin on what #15 and #17 measured: adding the third set changes none of it
        $this->assertSame([0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0], Ladder::RUNGS);
        foreach (['t-1', 't-2', 't-3', 't-4', 't-5', 'r-1', 'r-2', 'r-3', 'r-4', 'r-5'] as $id) {
            $this->assertSame(Ladder::RUNGS, Ladder::rungsFor($id), $id);
        }
        $this->assertSame(
            [['scale' => 10000, 'scenarios' => ['t-4']], ['scale' => 30000, 'scenarios' => ['t-1']], ['scale' => 100000, 'scenarios' => ['t-2', 't-3', 't-5']]],
            Ladder::order(Ladder::SET_FIRST),
        );
        $this->assertSame(
            [['scale' => 50000, 'scenarios' => ['r-1', 'r-2']], ['scale' => 200000, 'scenarios' => ['r-3', 'r-4', 'r-5']]],
            Ladder::order(Ladder::SET_RECHECK),
        );
        $expected = [
            't-1' => [30000, 3, 'even'], 't-2' => [100000, 10, 'even'], 't-3' => [100000, 10, 'first80'], 't-4' => [10000, 1, 'even'], 't-5' => [100000, 10, 'last80'],
            'r-1' => [50000, 5, 'even'], 'r-2' => [50000, 5, 'first80'], 'r-3' => [200000, 20, 'even'], 'r-4' => [200000, 20, 'first80'], 'r-5' => [200000, 20, 'last80'],
        ];
        foreach ($expected as $id => [$scale, $multiple, $mix]) {
            $this->assertSame(['id' => $id, 'scale' => $scale, 'multiple' => $multiple, 'mix' => $mix], Ladder::scenario($id), $id);
        }
        // no set holds another's scenario
        foreach (['t' => Ladder::SET_FIRST, 'r' => Ladder::SET_RECHECK, 's' => Ladder::SET_THIRD] as $prefix => $set) {
            foreach (Ladder::order($set) as $group) {
                foreach ($group['scenarios'] as $id) {
                    $this->assertStringStartsWith($prefix . '-', $id);
                }
            }
        }
    }

    public function test_a_trial_is_never_a_third_backtest_scenario_and_an_unknown_one_is_refused(): void
    {
        foreach (['s-1', 's-2', 's-3', 's-4', 's-5', 's-6', 's-0'] as $id) {
            try {
                Ladder::trial($id);
                $this->fail("expected a refusal for trial '$id'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['s-6', 's-0', 'S-1'] as $id) {
            try {
                Ladder::scenario($id);
                $this->fail("expected a refusal for '$id'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_every_step_of_the_third_ladder_makes_a_plan_for_every_mix_of_the_third_set_with_the_decided_total(): void
    {
        foreach (['s-1', 's-2', 's-3', 's-4', 's-5'] as $id) {
            $mix = Ladder::scenario($id)['mix'];
            foreach (Ladder::rungsFor($id) as $rps) {
                $plan = ScenarioPlan::build(self::routes(), $mix, $rps, 360, 60);
                $this->assertEqualsWithDelta($rps, array_sum(array_column($plan['routes'], 'rps')), 1e-9, "$id at $rps");
                $this->assertEqualsWithDelta(1.0, array_sum(array_column($plan['routes'], 'share')), 1e-12, "$id at $rps");
            }
        }
    }

    public function test_s5_sends_80_percent_to_the_even_places_of_the_list_at_every_step(): void
    {
        foreach (Ladder::RUNGS_S as $rps) {
            $plan = ScenarioPlan::build(self::routes(), Ladder::scenario('s-5')['mix'], $rps, 360, 60);
            $byRoute = array_column($plan['routes'], 'rps', 'route');
            $evenPlaces = $byRoute['GET /r2'] + $byRoute['GET /r4'] + $byRoute['GET /r6'] + $byRoute['GET /r8'];
            $oddPlaces = $byRoute['GET /r1'] + $byRoute['GET /r3'] + $byRoute['GET /r5'] + $byRoute['GET /r7'];
            $this->assertEqualsWithDelta(0.8 * $rps, $evenPlaces, 1e-9, "at $rps");
            $this->assertEqualsWithDelta(0.2 * $rps, $oddPlaces, 1e-9, "at $rps");
        }
    }

    public function test_a_step_under_80_percent_with_its_rps_out_goes_on(): void
    {
        $this->assertSame(['stop' => false, 'reasons' => [], 'note' => null], Ladder::decide(0.79999, true));
    }

    public function test_a_step_at_exactly_80_percent_stops_the_scenario(): void
    {
        $d = Ladder::decide(0.80, true);
        $this->assertTrue($d['stop']);
        $this->assertSame(['db_utilization_at_or_above_80_percent'], $d['reasons']);
        $this->assertNull($d['note']);
    }

    public function test_a_step_whose_rps_did_not_come_out_stops_the_scenario_and_is_called_an_app_side_stall_when_the_db_is_under_80(): void
    {
        $d = Ladder::decide(0.42, false);
        $this->assertTrue($d['stop']);
        $this->assertSame(['rps_not_reached'], $d['reasons']);
        $this->assertStringContainsString('app_side_stall', (string) $d['note']);
    }

    public function test_a_step_at_80_percent_whose_rps_did_not_come_out_gives_both_reasons_and_no_app_side_note(): void
    {
        $d = Ladder::decide(0.93, false);
        $this->assertSame(['db_utilization_at_or_above_80_percent', 'rps_not_reached'], $d['reasons']);
        $this->assertNull($d['note']);
    }

    public function test_the_arrival_preview_counts_a_whole_number_of_requests_per_route(): void
    {
        // 0.05 rps even = 8 routes at 1 per 160s: arrivals at 0, 160, 320; the kept window starts at 60 -> 2 per route
        $plan = ScenarioPlan::build(self::routes(), RouteMix::EVEN, 0.05, 360, 60);
        $p = Ladder::arrivalPreview($plan);
        $this->assertSame(16, $p['expected_requests']);
        $this->assertEqualsWithDelta(15.0, $p['decided_requests'], 1e-9);
        $this->assertEqualsWithDelta(16 / 15 - 1, $p['deviation'], 1e-9);
        $this->assertFalse($p['within_5_percent']);
    }

    public function test_the_arrival_preview_is_within_5_percent_at_a_step_with_many_requests(): void
    {
        $p = Ladder::arrivalPreview(ScenarioPlan::build(self::routes(), RouteMix::EVEN, 1.0, 360, 60));
        $this->assertSame(296, $p['expected_requests']);
        $this->assertTrue($p['within_5_percent']);
    }

    /** @return array<string, mixed> */
    private static function summary(float $util, bool $valid, ?float $app = 0.3): array
    {
        return [
            'mix' => 'even',
            'rps' => ['target_rps' => 1.0, 'achieved_rps' => $valid ? 1.0 : 0.7, 'ratio' => $valid ? 1.0 : 0.7, 'deviation' => 0.0, 'valid' => $valid, 'status' => $valid ? 'valid' : 'invalid'],
            'window_sec' => 300,
            'window_epoch' => ['from' => 100.0, 'to' => 400.0],
            'db_utilization' => ['utilization' => $util, 'percent' => $util * 100, 'delta_usage_usec' => 1.0, 'delta_sec' => 300.0, 'cpus' => 2],
            'requests' => ['in_window' => 300, 'failed' => 3, 'dropped_iterations' => 5, 'http_status' => ['200' => 290, '302' => 7, '500' => 3]],
            'per_route' => [],
        ] + ($app === null ? [] : ['app_cpu_utilization' => ['utilization' => $app, 'percent' => $app * 100, 'delta_usage_usec' => 1.0, 'delta_sec' => 300.0, 'cpus' => 6]]);
    }

    public function test_the_record_holds_every_item_the_step_must_write_down(): void
    {
        $meta = ['scenario' => 't-4', 'scale' => 10000, 'rung' => 9, 'started_at' => '2026-11-10T09:00:00+09:00', 'ended_at' => '2026-11-10T09:06:10+09:00'];
        $r = Ladder::record($meta, self::summary(0.55, true));

        $this->assertSame(1.0, $r['decided_rps']);
        $this->assertSame(1.0, $r['achieved_rps']);
        $this->assertTrue($r['rps_valid']);
        $this->assertSame(0.55, $r['db_utilization']);
        $this->assertSame(0.3, $r['app_cpu_utilization']);
        $this->assertSame(5, $r['k6_dropped_iterations']);
        $this->assertSame(['200' => 290, '302' => 7, '500' => 3], $r['http_status']);
        $this->assertSame('2026-11-10T09:00:00+09:00', $r['started_at']);
        $this->assertSame('2026-11-10T09:06:10+09:00', $r['ended_at']);
        $this->assertFalse($r['decision']['stop']);
    }

    public function test_the_record_keeps_the_three_limits_and_the_status_of_each_route_when_they_are_given(): void
    {
        $meta = ['scenario' => 'r-1', 'scale' => 50000, 'rung' => 2, 'started_at' => 'a', 'ended_at' => 'b', 'settings' => [
            'php_max_execution_time' => '0', 'php_fpm_request_terminate_timeout' => '0s', 'nginx_fastcgi_read_timeout' => '600s',
            'k6_request_timeout' => '600s', 'k6_request_timeout_note' => 'given',
        ]];
        $summary = self::summary(0.4, true);
        $summary['requests']['http_status_by_route'] = ['GET /a' => ['200' => 20], 'GET /b' => ['200' => 18, '500' => 2]];
        $summary['requests']['request_timeout'] = '600s';
        $r = Ladder::record($meta, $summary);

        $this->assertSame('0', $r['settings']['php_max_execution_time']);
        $this->assertSame('600s', $r['settings']['nginx_fastcgi_read_timeout']);
        $this->assertSame('600s', $r['settings']['k6_request_timeout']);
        $this->assertSame('600s', $r['settings']['k6_request_timeout_seen_by_k6']);
        $this->assertSame(['GET /a' => ['200' => 20], 'GET /b' => ['200' => 18, '500' => 2]], $r['http_status_by_route']);
    }

    public function test_a_record_without_settings_or_a_route_breakdown_has_nulls_and_the_rest_unchanged(): void
    {
        $meta = ['scenario' => 't-4', 'scale' => 10000, 'rung' => 9, 'started_at' => 'a', 'ended_at' => 'b'];
        $r = Ladder::record($meta, self::summary(0.55, true));
        $this->assertNull($r['settings']);
        $this->assertNull($r['http_status_by_route']);
        // what the first backtest's records held is still there, unchanged
        $this->assertSame(['200' => 290, '302' => 7, '500' => 3], $r['http_status']);
        $this->assertSame(0.55, $r['db_utilization']);
        $this->assertSame([], $r['per_route']);
    }

    public function test_a_trial_record_says_so_and_a_prediction_record_does_not(): void
    {
        $meta = ['scenario' => 'trial-cal-2', 'scale' => 10000, 'rung' => 1, 'started_at' => 'a', 'ended_at' => 'b', 'trial' => true];
        $this->assertTrue(Ladder::record($meta, self::summary(0.5, true))['trial']);
        unset($meta['trial']);
        $this->assertFalse(Ladder::record($meta, self::summary(0.5, true))['trial']);
    }

    public function test_a_stall_record_carries_the_app_cpu_and_says_when_it_was_not_sampled(): void
    {
        $meta = ['scenario' => 't-1', 'scale' => 30000, 'rung' => 4, 'started_at' => 'a', 'ended_at' => 'b'];
        $with = Ladder::record($meta, self::summary(0.40, false, 0.97));
        $this->assertSame(0.97, $with['app_cpu_utilization']);
        $this->assertStringContainsString('app_side_stall', (string) $with['decision']['note']);

        $without = Ladder::record($meta, self::summary(0.40, false, null));
        $this->assertNull($without['app_cpu_utilization']);
        $this->assertStringContainsString('not sampled', (string) $without['decision']['note']);
    }

    public function test_the_end_line_says_stop_with_the_reasons(): void
    {
        $meta = ['scenario' => 't-2', 'scale' => 100000, 'rung' => 6, 'started_at' => 'a', 'ended_at' => 'b'];
        $line = Ladder::endLine(Ladder::record($meta, self::summary(0.85, true)));
        $this->assertStringStartsWith('RUNG END t-2 rung 6 rps decided 1 achieved 1.0000 (valid) db 85.0% app 30.0% dropped 5', $line);
        $this->assertStringContainsString('STOP (db_utilization_at_or_above_80_percent)', $line);
        $this->assertStringContainsString('200x290 302x7 500x3', $line);
    }
}
