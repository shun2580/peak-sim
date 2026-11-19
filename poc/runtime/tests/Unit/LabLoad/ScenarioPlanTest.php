<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\RouteMix;
use PeakSim\Runtime\LabLoad\ScenarioPlan;
use PHPUnit\Framework\TestCase;

final class ScenarioPlanTest extends TestCase
{
    /** @return list<string> */
    private static function routes(): array
    {
        return array_map(static fn (int $i): string => "GET /r$i", range(1, 8));
    }

    public function test_an_even_mix_at_a_whole_rate_gives_whole_rates_per_second_and_keeps_the_list_order(): void
    {
        $plan = ScenarioPlan::build(self::routes(), RouteMix::EVEN, 80.0);

        $this->assertSame(600, $plan['duration_sec']);
        $this->assertSame(120, $plan['warmup_sec']);
        $this->assertSame(self::routes(), array_column($plan['routes'], 'route'));
        foreach ($plan['routes'] as $r) {
            $this->assertSame(10, $r['rate']);
            $this->assertSame('1s', $r['time_unit']);
        }
    }

    public function test_the_rps_of_the_routes_add_up_to_the_total_in_every_mix(): void
    {
        foreach (RouteMix::names() as $mix) {
            $plan = ScenarioPlan::build(self::routes(), $mix, 40.0);
            $this->assertEqualsWithDelta(40.0, array_sum(array_column($plan['routes'], 'rps')), 1e-9, $mix);
        }
    }

    public function test_a_first80_mix_at_40_rps_gives_8_and_2_per_second_to_the_two_halves(): void
    {
        $plan = ScenarioPlan::build(self::routes(), RouteMix::FIRST_80, 40.0);
        $this->assertSame([8, 8, 8, 8, 2, 2, 2, 2], array_column($plan['routes'], 'rate'));
        $this->assertSame(['1s'], array_values(array_unique(array_column($plan['routes'], 'time_unit'))));
    }

    public function test_a_rate_that_is_not_whole_per_second_is_written_per_1000_seconds(): void
    {
        $plan = ScenarioPlan::build(self::routes(), RouteMix::EVEN, 1.0);
        foreach ($plan['routes'] as $r) {
            $this->assertSame(125, $r['rate']);
            $this->assertSame('1000s', $r['time_unit']);
        }
    }

    public function test_every_rung_of_the_ladder_can_be_written_in_every_mix_the_ladder_uses(): void
    {
        foreach ([RouteMix::EVEN, RouteMix::FIRST_80, RouteMix::LAST_80] as $mix) {
            foreach ([0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0] as $rps) {
                $plan = ScenarioPlan::build(self::routes(), $mix, $rps, 360, 60);
                foreach ($plan['routes'] as $r) {
                    [$count, $unit] = [$r['rate'], (int) rtrim($r['time_unit'], 's')];
                    $this->assertGreaterThanOrEqual(1, $count);
                    $this->assertEqualsWithDelta($r['rps'], $count / $unit, $r['rps'] * 0.001, "$mix at $rps");
                }
            }
        }
    }

    public function test_a_rate_per_a_number_of_seconds_other_than_1_or_1000_is_found(): void
    {
        $plan = ScenarioPlan::build(self::routes(), RouteMix::EVEN, 0.05, 360, 60); // 0.00625 each = 1 per 160s
        $this->assertSame(1, $plan['routes'][0]['rate']);
        $this->assertSame('160s', $plan['routes'][0]['time_unit']);
        // 0.00875 each: the shortest unit that is within 0.1% (the tolerance every plan has had) is found, and it is not 1s or 1000s
        $plan = ScenarioPlan::build(self::routes(), RouteMix::EVEN, 0.07, 360, 60);
        $unit = (int) rtrim($plan['routes'][0]['time_unit'], 's');
        $this->assertNotContains($unit, [1, 1000]);
        $this->assertEqualsWithDelta(0.00875, $plan['routes'][0]['rate'] / $unit, 0.00875 * 0.001);
    }

    public function test_a_rate_k6_cannot_express_is_refused_not_rounded_silently(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScenarioPlan::build(self::routes(), RouteMix::EVEN, 0.0001);
    }

    public function test_bad_inputs_are_refused(): void
    {
        foreach ([
            static fn () => ScenarioPlan::build(self::routes(), RouteMix::EVEN, 0.0),
            static fn () => ScenarioPlan::build(self::routes(), RouteMix::EVEN, -3.0),
            static fn () => ScenarioPlan::build(self::routes(), RouteMix::EVEN, 10.0, 600, 600),
            static fn () => ScenarioPlan::build(['GET /a', 'GET /a'], RouteMix::EVEN, 10.0),
        ] as $call) {
            try {
                $call();
                $this->fail('expected a refusal');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_book_page_mix_leaves_the_zip_out_and_sends_the_rest_at_their_rates(): void
    {
        $routes = [
            'GET /books/{bookSlug}/chapter/{chapterSlug}/references',
            'GET /books/{bookSlug}/export/zip',
            'GET /books/{bookSlug}/page/{pageSlug}/references',
            'GET /books/{slug}',
            'GET /books/{slug}/references',
            'GET /images/edit/{id}',
            'GET /oidc/callback',
            'GET /shelves/{slug}',
        ];
        $plan = ScenarioPlan::build($routes, RouteMix::BOOK_PAGE_80_ZIP_0, 2.2, 600, 120);

        $this->assertSame(RouteMix::BOOK_PAGE_80_ZIP_0, $plan['mix']);
        $this->assertSame(array_values(array_diff($routes, ['GET /books/{bookSlug}/export/zip'])), array_column($plan['routes'], 'route'));
        $byRoute = array_column($plan['routes'], null, 'route');
        $this->assertEqualsWithDelta(0.8, $byRoute['GET /books/{slug}']['share'], 1e-12);
        $this->assertEqualsWithDelta(1.76, $byRoute['GET /books/{slug}']['rps'], 1e-9);
        $this->assertEqualsWithDelta(2.2, array_sum(array_column($plan['routes'], 'rps')), 1e-9);
        foreach ($plan['routes'] as $r) {
            $this->assertGreaterThanOrEqual(1, $r['rate']);
            $this->assertEqualsWithDelta($r['rps'], $r['rate'] / (int) rtrim($r['time_unit'], 's'), $r['rps'] * 0.001, $r['route']);
        }
    }

    public function test_the_plan_of_an_existing_mix_is_the_same_as_before_and_keeps_every_route(): void
    {
        $plan = ScenarioPlan::build(self::routes(), RouteMix::ODD_80, 40.0);
        $this->assertCount(8, $plan['routes']);
        $this->assertSame([8, 2, 8, 2, 8, 2, 8, 2], array_column($plan['routes'], 'rate'));
        $this->assertSame(['mix', 'rps', 'duration_sec', 'warmup_sec', 'routes'], array_keys($plan));
    }

    // ---- an allocation (decisions.md D226 and D227): the shares are the prediction's rps_after, a dropped route is not sent ----------------

    /**
     * What `predict-ladder --drop-marked-routes` hands out: the routes of a group (the same share in the mix) keep the group's total
     * between the routes that stay.
     *
     * @param list<string> $routes
     * @param list<string> $dropped
     *
     * @return array{rps_after: array<string, float>, dropped: list<string>}
     */
    private static function allocationFor(array $routes, string $mix, array $dropped): array
    {
        $shares = RouteMix::shares(count($routes), $mix, $routes);
        $inFile = array_combine($routes, $shares);
        $groups = [];
        foreach ($inFile as $route => $share) {
            $groups[sprintf('%.12f', $share)][] = $route;
        }
        $after = [];
        foreach ($groups as $members) {
            $kept = array_values(array_diff($members, $dropped));
            if ($kept === []) {
                continue;
            }
            $factor = count($members) / count($kept);
            foreach ($kept as $route) {
                $after[$route] = $inFile[$route] * $factor;
            }
        }

        return ['rps_after' => $after, 'dropped' => $dropped];
    }

    public function test_an_allocation_sends_the_shares_of_rps_after_and_leaves_the_dropped_route_out(): void
    {
        $routes = self::routes();
        $allocation = self::allocationFor($routes, RouteMix::EVEN, ['GET /r4']);
        $plan = ScenarioPlan::build($routes, RouteMix::EVEN, 1.4, 360, 60, $allocation);

        $this->assertSame(array_values(array_diff($routes, ['GET /r4'])), array_column($plan['routes'], 'route'));
        foreach ($plan['routes'] as $r) {
            $this->assertEqualsWithDelta(1 / 7, $r['share'], 1e-12);
            $this->assertEqualsWithDelta(0.2, $r['rps'], 1e-12);
        }
        $this->assertEqualsWithDelta(1.4, array_sum(array_column($plan['routes'], 'rps')), 1e-9);
        $this->assertSame(RouteMix::EVEN, $plan['mix']);
        $this->assertSame(['GET /r4'], $plan['allocation']['routes_not_sent']);
        $this->assertSame(array_column($plan['routes'], 'route'), $plan['allocation']['routes_sent']);
    }

    public function test_an_allocation_keeps_the_80_20_proportions_of_the_groups_that_stay(): void
    {
        $routes = self::routes();
        $allocation = self::allocationFor($routes, RouteMix::FIRST_80, ['GET /r2']); // one of the four routes with 80% in all
        $plan = ScenarioPlan::build($routes, RouteMix::FIRST_80, 10.0, 360, 60, $allocation);
        $byRoute = array_column($plan['routes'], null, 'route');

        $this->assertArrayNotHasKey('GET /r2', $byRoute);
        foreach (['GET /r1', 'GET /r3', 'GET /r4'] as $heavy) {
            $this->assertEqualsWithDelta(0.8 / 3, $byRoute[$heavy]['share'], 1e-12);
        }
        foreach (['GET /r5', 'GET /r6', 'GET /r7', 'GET /r8'] as $light) {
            $this->assertEqualsWithDelta(0.05, $byRoute[$light]['share'], 1e-12);
        }
        $this->assertEqualsWithDelta(10.0, array_sum(array_column($plan['routes'], 'rps')), 1e-9);
    }

    public function test_without_an_allocation_the_plan_is_what_it_always_was_and_has_no_extra_key(): void
    {
        foreach (RouteMix::names() as $mix) {
            $with = ScenarioPlan::build(self::routes(), $mix, 0.45, 356, 60, null);
            $without = ScenarioPlan::build(self::routes(), $mix, 0.45, 356, 60);
            $this->assertSame($without, $with, $mix);
            $this->assertSame(['mix', 'rps', 'duration_sec', 'warmup_sec', 'routes'], array_keys($with), $mix);
            $this->assertCount(8, $with['routes'], $mix);
        }
    }

    public function test_every_rung_of_the_b_ladder_can_be_written_with_any_one_route_or_any_two_routes_of_a_group_dropped_in_every_s_mix(): void
    {
        $routes = self::routes();
        $rungs = \PeakSim\Runtime\LabLoad\Ladder::rungsFor('s-1');
        $this->assertCount(15, $rungs);
        $drops = [];
        foreach ($routes as $a) {
            $drops[] = [$a];
        }
        $drops[] = ['GET /r1', 'GET /r2'];
        $drops[] = ['GET /r7', 'GET /r8'];
        $drops[] = ['GET /r4', 'GET /r5'];
        foreach ([RouteMix::EVEN, RouteMix::FIRST_80, RouteMix::LAST_80, RouteMix::PLACES_1235, RouteMix::PLACES_1345] as $mix) {
            foreach ($drops as $dropped) {
                $allocation = self::allocationFor($routes, $mix, $dropped);
                foreach ($rungs as $rps) {
                    $plan = ScenarioPlan::build($routes, $mix, (float) $rps, 360, 60, $allocation);
                    $label = "$mix without " . implode(',', $dropped) . " at $rps";
                    $this->assertEqualsWithDelta((float) $rps, array_sum(array_column($plan['routes'], 'rps')), 1e-9, $label);
                    foreach ($plan['routes'] as $r) {
                        $this->assertNotContains($r['route'], $dropped, $label);
                        $this->assertGreaterThanOrEqual(1, $r['rate'], $label);
                        $this->assertEqualsWithDelta($r['rps'], $r['rate'] / (int) rtrim($r['time_unit'], 's'), $r['rps'] * 0.001, $label);
                    }
                }
            }
        }
    }

    public function test_an_allocation_that_does_not_fit_the_list_is_refused_and_nothing_is_planned(): void
    {
        $routes = self::routes();
        $good = self::allocationFor($routes, RouteMix::EVEN, ['GET /r4']);
        $bad = [
            'no rps_after' => ['dropped' => []],
            'an empty rps_after' => ['rps_after' => [], 'dropped' => []],
            'a route that is not in the list' => ['rps_after' => $good['rps_after'] + ['GET /nowhere' => 0.1], 'dropped' => ['GET /r4']],
            'an rps of 0' => ['rps_after' => ['GET /r1' => 0.0, 'GET /r2' => 1.0], 'dropped' => []],
            'a negative rps' => ['rps_after' => ['GET /r1' => -1.0, 'GET /r2' => 1.0], 'dropped' => []],
            'a dropped route that still has an rps' => ['rps_after' => $good['rps_after'], 'dropped' => ['GET /r1']],
        ];
        foreach ($bad as $label => $allocation) {
            try {
                ScenarioPlan::build($routes, RouteMix::EVEN, 1.0, 360, 60, $allocation);
                $this->fail("$label was not refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
