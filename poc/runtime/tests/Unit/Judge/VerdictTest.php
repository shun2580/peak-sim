<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Judge;

use PeakSim\Runtime\Judge\Verdict;
use PHPUnit\Framework\TestCase;

final class VerdictTest extends TestCase
{
    private const SCALES = [10, 100, 1000];

    public function test_a_flat_series_that_touched_the_data_is_measured_o1(): void
    {
        $v = Verdict::fromSweep('targeted:orders', self::SCALES, [4, 4, 4]);

        $this->assertSame(Verdict::MEASURED_O1, $v->kind);
        $this->assertSame('O(1)', $v->growth());
        $this->assertSame('targeted:orders', $v->scope);
        $this->assertFalse($v->isNoInfo());
    }

    public function test_a_growing_series_is_measured_on(): void
    {
        $v = Verdict::fromSweep('global', self::SCALES, [11, 101, 1001]);

        $this->assertSame(Verdict::MEASURED_ON, $v->kind);
        $this->assertSame('O(N)', $v->growth());
        $this->assertEqualsWithDelta(1.0, $v->slope, 0.01);
    }

    public function test_an_o1_that_never_touched_the_scaled_tables_is_no_information(): void
    {
        $v = Verdict::fromSweep('targeted:orders', self::SCALES, [4, 4, 4], untouchedData: true);

        $this->assertSame(Verdict::NO_INFO, $v->kind);
        $this->assertSame('untouched_data', $v->reason);
        $this->assertNull($v->growth());
        $this->assertTrue($v->isNoInfo());
    }

    public function test_an_o1_whose_response_stayed_empty_is_no_information(): void
    {
        $v = Verdict::fromSweep('global', self::SCALES, [2, 2, 2], suspectEmpty: true);

        $this->assertSame(Verdict::NO_INFO, $v->kind);
        $this->assertSame('suspect_empty', $v->reason);
    }

    public function test_both_reasons_are_kept_when_both_apply(): void
    {
        $v = Verdict::fromSweep('global', self::SCALES, [2, 2, 2], untouchedData: true, suspectEmpty: true);

        $this->assertSame('untouched_data,suspect_empty', $v->reason);
    }

    public function test_growth_that_was_seen_is_not_discarded_by_the_empty_response_flag(): void
    {
        // Queries grew with N: that is positive evidence whatever the body looked like.
        $v = Verdict::fromSweep('global', self::SCALES, [11, 101, 1001], suspectEmpty: true);

        $this->assertSame(Verdict::MEASURED_ON, $v->kind);
    }

    public function test_the_in_between_zone_stays_inconclusive_and_says_no_growth_class(): void
    {
        $v = Verdict::fromSweep('global', self::SCALES, [100, 1000, 2239]);

        $this->assertSame(Verdict::INCONCLUSIVE, $v->kind);
        $this->assertNull($v->growth());
    }

    public function test_fewer_than_three_points_is_not_measured_with_a_reason(): void
    {
        $v = Verdict::fromSweep('global', [100, 1000], [100, 1000]);

        $this->assertSame(Verdict::NOT_MEASURED, $v->kind);
        $this->assertSame('insufficient_points', $v->reason);
    }

    public function test_a_product_is_only_said_for_a_targeted_scope_with_both_sweeps_linear(): void
    {
        $v = Verdict::fromSweeps('targeted:orders', self::SCALES, [10, 100, 1000], [3, 30, 300], [3, 30, 300]);

        $this->assertSame(Verdict::MEASURED_ONM, $v->kind);
        $this->assertSame('O(N*M)', $v->growth());
        $this->assertNotNull($v->slopeM);
    }

    public function test_one_linear_sweep_and_one_flat_sweep_is_measured_on(): void
    {
        $v = Verdict::fromSweeps('targeted:orders', self::SCALES, [10, 100, 1000], [3, 30, 300], [5, 5, 5]);

        $this->assertSame(Verdict::MEASURED_ON, $v->kind);
    }

    public function test_a_global_scope_never_says_o_n_times_m(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Verdict::fromSweeps('global', self::SCALES, [10, 100, 1000], [3, 30, 300], [3, 30, 300]);
    }

    public function test_a_verdict_without_a_scope_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Verdict::fromSweep('', self::SCALES, [4, 4, 4]);
    }

    public function test_an_unknown_scope_form_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Verdict::fromSweep('everything', self::SCALES, [4, 4, 4]);
    }

    public function test_not_measured_carries_its_reason_and_no_growth(): void
    {
        $v = Verdict::notMeasured('unfilled_param');

        $this->assertSame(Verdict::NOT_MEASURED, $v->kind);
        $this->assertSame('unfilled_param', $v->reason);
        $this->assertNull($v->growth());
        $this->assertNull($v->scope);
    }

    public function test_the_gate_bypass_flag_is_kept(): void
    {
        $v = Verdict::fromSweep('global', self::SCALES, [4, 4, 4], gateBypass: true);

        $this->assertTrue($v->gateBypass);
        $this->assertTrue($v->toArray()['gate_bypass']);
    }

    public function test_the_array_form_has_the_columns_of_the_report(): void
    {
        $a = Verdict::fromSweep('targeted:orders', self::SCALES, [11, 101, 1001])->toArray();

        foreach (['verdict', 'scope', 'scales', 'values', 'slope', 'gate_bypass', 'reason', 'statement'] as $key) {
            $this->assertArrayHasKey($key, $a);
        }
        $this->assertSame('measured_ON', $a['verdict']);
    }

    public function test_the_statement_names_the_input_and_the_path_and_the_scope(): void
    {
        $text = Verdict::fromSweep('targeted:orders', self::SCALES, [11, 101, 1001])->toArray()['statement'];

        $this->assertStringContainsString('this input', $text);
        $this->assertStringContainsString('path', $text);
        $this->assertStringContainsString('targeted:orders', $text);
    }

    public function test_the_statement_of_no_information_does_not_say_o1(): void
    {
        $text = Verdict::fromSweep('global', self::SCALES, [2, 2, 2], untouchedData: true)->toArray()['statement'];

        $this->assertStringNotContainsString('O(1)', $text);
        $this->assertStringContainsString('no information', $text);
    }
}
