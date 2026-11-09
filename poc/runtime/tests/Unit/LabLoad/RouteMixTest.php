<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\RouteMix;
use PHPUnit\Framework\TestCase;

final class RouteMixTest extends TestCase
{
    public function test_even_gives_every_route_the_same_share(): void
    {
        $shares = RouteMix::shares(8, RouteMix::EVEN);
        $this->assertCount(8, $shares);
        foreach ($shares as $s) {
            $this->assertEqualsWithDelta(0.125, $s, 1e-12);
        }
    }

    public function test_first80_puts_80_percent_on_the_first_four_and_20_on_the_last_four(): void
    {
        $shares = RouteMix::shares(8, RouteMix::FIRST_80);
        $this->assertEqualsWithDelta(0.8, array_sum(array_slice($shares, 0, 4)), 1e-12);
        $this->assertEqualsWithDelta(0.2, array_sum(array_slice($shares, 4)), 1e-12);
        $this->assertEqualsWithDelta(0.2, $shares[0], 1e-12);
        $this->assertEqualsWithDelta(0.05, $shares[7], 1e-12);
    }

    public function test_last80_is_the_reverse_of_first80(): void
    {
        $first = RouteMix::shares(8, RouteMix::FIRST_80);
        $last = RouteMix::shares(8, RouteMix::LAST_80);
        $this->assertEqualsWithDelta($first, array_reverse($last), 1e-12);
        $this->assertEqualsWithDelta(0.8, array_sum(array_slice($last, 4)), 1e-12);
    }

    public function test_every_mix_adds_up_to_one(): void
    {
        foreach (RouteMix::names() as $mix) {
            $this->assertEqualsWithDelta(1.0, array_sum(RouteMix::shares(8, $mix)), 1e-12, $mix);
        }
    }

    public function test_an_unknown_mix_or_an_odd_or_short_list_is_refused(): void
    {
        foreach ([[8, 'random'], [7, RouteMix::EVEN], [0, RouteMix::EVEN], [1, RouteMix::EVEN]] as [$n, $mix]) {
            try {
                RouteMix::shares($n, $mix);
                $this->fail("expected a refusal for $n / $mix");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
