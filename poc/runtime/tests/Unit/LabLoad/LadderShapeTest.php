<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\Ladder;
use PeakSim\Runtime\LabLoad\LadderShape;
use PeakSim\Runtime\LabLoad\RouteMix;
use PeakSim\Runtime\LabLoad\ScenarioPlan;
use PHPUnit\Framework\TestCase;

final class LadderShapeTest extends TestCase
{
    /** @return list<string> */
    private static function routes(): array
    {
        return array_map(static fn (int $i): string => "GET /r$i", range(1, 8));
    }

    /** @return array<string, mixed> a plan made the way make-plan makes it, for the length the scheme gives */
    private static function plan(string $mix, float $rps, string $scheme): array
    {
        $shape = LadderShape::shape($scheme, $rps);

        return LadderShape::stagger(ScenarioPlan::build(self::routes(), $mix, $rps, $shape['duration_sec'], $shape['warmup_sec']), $scheme);
    }

    public function test_the_default_scheme_is_the_spec_as_written(): void
    {
        foreach (Ladder::RUNGS as $rps) {
            $this->assertSame(['duration_sec' => 360, 'warmup_sec' => 60], LadderShape::shape(LadderShape::SPEC, $rps));
            $this->assertSame(['duration_sec' => 360, 'warmup_sec' => 60], LadderShape::shape(LadderShape::A, $rps));
        }
    }

    public function test_the_two_steps_added_for_the_third_backtest_get_their_windows_from_the_same_rule_and_the_same_rounding(): void
    {
        // 0.025: 40 / 0.025 = 1600 s, already at least 300 s. 0.035: 40 / 0.035 = 1142.857 s, rounded to whole seconds as 0.07 (571.43 -> 571) is.
        foreach ([[0.025, 1600], [0.035, 1143]] as [$rps, $window]) {
            $this->assertSame($window, LadderShape::multipleWindow($rps), "rps $rps");
            $shape = LadderShape::shape(LadderShape::B, $rps);
            $this->assertSame($window + 60, $shape['duration_sec'], "rps $rps");
            $this->assertSame(60, $shape['warmup_sec']);
            $this->assertSame(300 + 60, LadderShape::shape(LadderShape::SPEC, $rps)['duration_sec'], "spec, rps $rps");
        }
        // every step of the 15 has a window of at least 300 s under B (the 13 old windows are pinned in the next test)
        foreach (Ladder::RUNGS_S as $rps) {
            $this->assertGreaterThanOrEqual(300, LadderShape::multipleWindow($rps), "rps $rps");
        }
    }

    public function test_scheme_b_lengthens_the_window_to_the_smallest_multiple_of_40_over_rps_of_at_least_300_seconds(): void
    {
        $windows = [[0.05, 800], [0.07, 571], [0.1, 400], [0.15, 533], [0.2, 400], [0.3, 400], [0.45, 356], [0.7, 343], [1.0, 320], [1.5, 320], [2.2, 309], [3.3, 303], [5.0, 304]];
        foreach ($windows as [$rps, $window]) {
            $shape = LadderShape::shape(LadderShape::B, $rps);
            $this->assertSame($window + 60, $shape['duration_sec'], "rps $rps");
            $this->assertSame(60, $shape['warmup_sec']);
        }
    }

    public function test_an_unknown_scheme_and_a_bad_rps_are_refused(): void
    {
        foreach ([static fn () => LadderShape::shape('C', 1.0), static fn () => LadderShape::shape('A', 0.0), static fn () => LadderShape::stagger(self::plan('even', 1.0, 'spec'), 'x')] as $call) {
            try {
                $call();
                $this->fail('expected a refusal');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_spec_scheme_leaves_every_route_starting_at_zero(): void
    {
        $plan = self::plan(RouteMix::EVEN, 0.05, LadderShape::SPEC);
        $this->assertSame('spec', $plan['scheme']);
        foreach ($plan['routes'] as $r) {
            $this->assertArrayNotHasKey('start_offset_sec', $r);
        }
        $this->assertSame(16, Ladder::arrivalPreview($plan)['expected_requests']); // the arithmetic that made the problem
    }

    public function test_planned_counts_give_the_ceiling_to_the_largest_fractions_first_and_ties_to_the_earlier_route(): void
    {
        // 8 routes at 0.00625 rps over 300 s = 1.875 each; the total is 15, so 7 routes get 2 and the last one 1
        $this->assertSame([2, 2, 2, 2, 2, 2, 2, 1], LadderShape::plannedCounts(array_fill(0, 8, 0.00625), 300, 0.05));
        // first80 at 0.05: 4 routes at 0.01 (3.0 each) and 4 at 0.0025 (0.75 each): 12 + 3 = 15
        $this->assertSame([3, 3, 3, 3, 1, 1, 1, 0], LadderShape::plannedCounts([0.01, 0.01, 0.01, 0.01, 0.0025, 0.0025, 0.0025, 0.0025], 300, 0.05));
    }

    /** @return iterable<string, array{0: string, 1: float, 2: string}> */
    public static function everyStep(): iterable
    {
        foreach ([LadderShape::A, LadderShape::B] as $scheme) {
            foreach ([RouteMix::EVEN, RouteMix::FIRST_80, RouteMix::LAST_80] as $mix) {
                foreach (Ladder::RUNGS as $rps) {
                    yield "$scheme $mix $rps" => [$mix, $rps, $scheme];
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyStep')]
    public function test_every_step_of_every_mix_gets_exactly_the_decided_total_and_the_planned_count_per_route(string $mix, float $rps, string $scheme): void
    {
        $plan = self::plan($mix, $rps, $scheme);
        $window = $plan['duration_sec'] - $plan['warmup_sec'];
        $total = 0;
        foreach ($plan['routes'] as $r) {
            $spacing = (int) rtrim($r['time_unit'], 's') / $r['rate'];
            $n = LadderShape::countIn($r['start_offset_sec'], $spacing, $plan['warmup_sec'], $plan['duration_sec']);
            $this->assertSame($r['planned_window_requests'], $n, $r['route']);
            $this->assertLessThan($plan['duration_sec'], $r['start_offset_sec'] + 1, 'a stream must start before the step ends');
            $total += $n;
        }
        $this->assertSame((int) round($rps * $window), $total);
        $preview = Ladder::arrivalPreview($plan);
        $this->assertTrue($preview['within_5_percent']);
        $this->assertLessThan(0.005, abs($preview['deviation']), 'the decided total, up to the rounding of the window to whole seconds');
        // no arrival is left within 0.2 s of an edge of the window (the narrowest is about 0.23 s at 3.3 rps), so a few milliseconds
        // of jitter move no request
        $this->assertGreaterThan(0.2, $preview['min_edge_margin_sec']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyStep')]
    public function test_a_route_is_never_more_than_one_request_from_its_share_of_the_window(string $mix, float $rps, string $scheme): void
    {
        $preview = Ladder::arrivalPreview(self::plan($mix, $rps, $scheme));
        $this->assertLessThan(1.0, $preview['max_route_count_error'] + 1e-9);
    }

    public function test_scheme_b_gives_every_route_a_whole_number_of_requests_its_share_exactly(): void
    {
        $plan = self::plan(RouteMix::FIRST_80, 0.07, LadderShape::B);
        $window = $plan['duration_sec'] - $plan['warmup_sec'];
        foreach ($plan['routes'] as $r) {
            $this->assertEqualsWithDelta($r['planned_window_requests'], $r['rps'] * $window, 0.06, $r['route']);
        }
    }

    public function test_place_returns_null_when_no_start_time_gives_the_count(): void
    {
        // a request every 100 s over a 300 s window has 3 arrivals in it, never 5
        $this->assertNull(LadderShape::place(100.0, 5, 60, 360));
        $phi = LadderShape::place(100.0, 3, 60, 360);
        $this->assertNotNull($phi);
        $this->assertSame(3, LadderShape::countIn($phi, 100.0, 60, 360));
    }

    public function test_count_in_counts_arrivals_from_the_start_of_the_stream(): void
    {
        $this->assertSame(2, LadderShape::countIn(0.0, 160.0, 60, 360));   // 160, 320
        $this->assertSame(1, LadderShape::countIn(40.0, 160.0, 60, 360));  // 40 is before the window, 200 is in, 360 is the end
    }
}
