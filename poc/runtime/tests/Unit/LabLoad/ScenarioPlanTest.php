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
}
