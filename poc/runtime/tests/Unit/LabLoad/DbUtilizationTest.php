<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\CpuStatUnreadable;
use PeakSim\Runtime\LabLoad\DbUtilization;
use PHPUnit\Framework\TestCase;

final class DbUtilizationTest extends TestCase
{
    private const CPU_STAT = "usage_usec 37707\nuser_usec 18853\nsystem_usec 18853\nnr_periods 0\nnr_throttled 0\nthrottled_usec 0\n";

    public function test_usage_usec_is_read_from_a_cpu_stat_text(): void
    {
        $this->assertSame(37707, DbUtilization::parseUsageUsec(self::CPU_STAT));
    }

    public function test_a_cpu_stat_without_usage_usec_stops_instead_of_giving_a_figure(): void
    {
        foreach (['', "user_usec 10\nsystem_usec 5\n", "usage_usec abc\n"] as $text) {
            try {
                DbUtilization::parseUsageUsec($text);
                $this->fail('expected CpuStatUnreadable');
            } catch (CpuStatUnreadable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_utilization_is_delta_usage_over_delta_time_times_two_cpus(): void
    {
        // 60 CPU-seconds of usage over 120 s on 2 CPUs: 60 / (120 x 2) = 25%.
        $samples = [['t' => 1000.0, 'usage_usec' => 5_000_000], ['t' => 1120.0, 'usage_usec' => 65_000_000]];
        $r = DbUtilization::fromSamples($samples, 1000.0, 1120.0);
        $this->assertEqualsWithDelta(0.25, $r['utilization'], 1e-12);
        $this->assertEqualsWithDelta(25.0, $r['percent'], 1e-9);
        $this->assertEqualsWithDelta(60_000_000.0, $r['delta_usage_usec'], 1e-6);
        $this->assertSame(2, $r['cpus']);
    }

    public function test_the_edges_of_the_window_are_interpolated_between_the_samples_around_them(): void
    {
        // Steady 1 CPU (1,000,000 usec per second) from t=0; window 12..112 lies between samples at 10/15 and 110/115.
        $samples = [];
        foreach ([0, 5, 10, 15, 60, 105, 110, 115, 120] as $t) {
            $samples[] = ['t' => (float) $t, 'usage_usec' => $t * 1_000_000];
        }
        $r = DbUtilization::fromSamples($samples, 12.0, 112.0);
        $this->assertEqualsWithDelta(100_000_000.0, $r['delta_usage_usec'], 1e-3);
        $this->assertEqualsWithDelta(0.5, $r['utilization'], 1e-9);
    }

    public function test_samples_that_do_not_cover_the_window_stop(): void
    {
        $samples = [['t' => 10.0, 'usage_usec' => 0], ['t' => 20.0, 'usage_usec' => 10]];
        foreach ([[5.0, 15.0], [15.0, 25.0]] as [$a, $b]) {
            try {
                DbUtilization::fromSamples($samples, $a, $b);
                $this->fail('expected CpuStatUnreadable');
            } catch (CpuStatUnreadable) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(CpuStatUnreadable::class);
        DbUtilization::fromSamples([], 0.0, 1.0);
    }

    public function test_a_usage_that_goes_down_or_samples_out_of_order_stop(): void
    {
        foreach ([
            [['t' => 0.0, 'usage_usec' => 100], ['t' => 10.0, 'usage_usec' => 50]],
            [['t' => 10.0, 'usage_usec' => 0], ['t' => 5.0, 'usage_usec' => 50]],
        ] as $samples) {
            try {
                DbUtilization::fromSamples($samples, 5.0, 8.0);
                $this->fail('expected CpuStatUnreadable');
            } catch (CpuStatUnreadable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_samples_file_is_parsed_line_by_line_and_a_bad_line_stops(): void
    {
        $samples = DbUtilization::parseSamples("1700000000.250 1000\n1700000005.5 6000\n\n");
        $this->assertSame([['t' => 1700000000.25, 'usage_usec' => 1000], ['t' => 1700000005.5, 'usage_usec' => 6000]], $samples);
        $this->expectException(CpuStatUnreadable::class);
        DbUtilization::parseSamples("1700000000.250 oops\n");
    }
}
