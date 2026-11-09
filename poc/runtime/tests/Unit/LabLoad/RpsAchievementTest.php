<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\RpsAchievement;
use PHPUnit\Framework\TestCase;

final class RpsAchievementTest extends TestCase
{
    public function test_exactly_the_target_is_valid(): void
    {
        $r = RpsAchievement::judge(50.0, 24000, 480);
        $this->assertEqualsWithDelta(50.0, $r['achieved_rps'], 1e-12);
        $this->assertTrue($r['valid']);
        $this->assertSame('valid', $r['status']);
    }

    public function test_five_percent_either_way_is_valid_and_just_past_it_is_invalid(): void
    {
        $this->assertSame('valid', RpsAchievement::judge(100.0, 105 * 480, 480)['status']);
        $this->assertSame('valid', RpsAchievement::judge(100.0, 95 * 480, 480)['status']);
        $this->assertSame('invalid', RpsAchievement::judge(100.0, 105 * 480 + 25, 480)['status']);
        $this->assertSame('invalid', RpsAchievement::judge(100.0, 95 * 480 - 25, 480)['status']);
    }

    public function test_a_scenario_that_fell_short_is_marked_invalid_with_its_ratio(): void
    {
        $r = RpsAchievement::judge(200.0, 60000, 480);
        $this->assertFalse($r['valid']);
        $this->assertEqualsWithDelta(0.625, $r['ratio'], 1e-12);
        $this->assertEqualsWithDelta(-0.375, $r['deviation'], 1e-12);
    }

    public function test_bad_inputs_are_refused(): void
    {
        foreach ([[0.0, 10, 480], [10.0, -1, 480], [10.0, 10, 0]] as [$t, $c, $w]) {
            try {
                RpsAchievement::judge($t, $c, $w);
                $this->fail('expected a refusal');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
