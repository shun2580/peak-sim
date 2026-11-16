<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\Ladder;
use PeakSim\Runtime\LabLoad\RpsAchievement;
use PeakSim\Runtime\LabLoad\StepReread;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Section 7.4.3: "did the decided rps come out" is counted on the requests SENT inside the window, a step in which the load tool dropped
 * an arrival is invalid, and a step recorded before the rule can be read again without any of its records changing.
 */
final class SentCountTest extends TestCase
{
    private const DATA = __DIR__ . '/../../../../../docs/research/lab-data';

    /** The shape of the third run's s-1 step at 0.1 rps: 40 requests are decided for the 400 s window, 3 were still running at the end. */
    public function test_a_step_with_requests_still_running_at_its_end_is_invalid_by_the_finished_count_and_valid_by_the_sent_count(): void
    {
        $finished = RpsAchievement::judge(0.1, 37, 400);
        $this->assertFalse($finished['valid'], 'the old count (37 finished of 40 = 0.0925 rps) is outside 5%');

        $sent = RpsAchievement::judgeStep(0.1, 40, 400, 0);
        $this->assertTrue($sent['valid']);
        $this->assertSame('valid', $sent['status']);
        $this->assertSame('sent', $sent['counted']);
        $this->assertSame([], $sent['invalid_reasons']);
        $this->assertEqualsWithDelta(0.1, $sent['achieved_rps'], 1e-12);
    }

    public function test_one_dropped_arrival_makes_the_step_invalid_whatever_the_count(): void
    {
        $r = RpsAchievement::judgeStep(0.1, 40, 400, 1);
        $this->assertFalse($r['valid']);
        $this->assertSame('invalid', $r['status']);
        $this->assertSame([RpsAchievement::REASON_DROPPED], $r['invalid_reasons']);
        $this->assertSame(1, $r['dropped_iterations']);
    }

    public function test_a_count_outside_5_percent_is_invalid_for_that_reason_and_both_reasons_are_given_together(): void
    {
        $this->assertSame([RpsAchievement::REASON_OUTSIDE_TOLERANCE], RpsAchievement::judgeStep(1.0, 100, 400, 0)['invalid_reasons']);
        $this->assertSame(
            [RpsAchievement::REASON_OUTSIDE_TOLERANCE, RpsAchievement::REASON_DROPPED],
            RpsAchievement::judgeStep(1.0, 100, 400, 7)['invalid_reasons'],
        );
        $this->assertTrue(RpsAchievement::judgeStep(1.0, 420, 400, 0)['valid'], 'exactly 5% is still valid, as before');
    }

    public function test_judge_itself_is_unchanged(): void
    {
        $this->assertSame(['target_rps', 'achieved_rps', 'ratio', 'deviation', 'valid', 'status'], array_keys(RpsAchievement::judge(1.0, 400, 400)));
    }

    public function test_a_negative_dropped_count_or_an_unknown_counting_is_refused(): void
    {
        foreach ([[-1, 'sent'], [0, 'finished']] as [$dropped, $counted]) {
            try {
                RpsAchievement::judgeStep(1.0, 400, 400, $dropped, $counted);
                $this->fail('expected a refusal');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_decision_for_a_dropped_arrival_says_so_and_stops_the_scenario(): void
    {
        $d = Ladder::decide(0.30, false, [RpsAchievement::REASON_DROPPED]);
        $this->assertTrue($d['stop']);
        $this->assertSame([RpsAchievement::REASON_DROPPED], $d['reasons']);
        $this->assertStringContainsString('dropped arrivals', (string) $d['note']);
        $this->assertStringNotContainsString('app_side_stall', (string) $d['note']);
    }

    public function test_the_decision_without_reasons_is_what_it_was_before(): void
    {
        $this->assertSame(['stop' => true, 'reasons' => ['rps_not_reached'], 'note' => 'app_side_stall: the rps did not come out while the DB was under 80%'], Ladder::decide(0.42, false));
        $this->assertSame(['stop' => false, 'reasons' => [], 'note' => null], Ladder::decide(0.42, true));
        $both = Ladder::decide(0.42, false, [RpsAchievement::REASON_OUTSIDE_TOLERANCE, RpsAchievement::REASON_DROPPED]);
        $this->assertSame(['rps_not_reached', RpsAchievement::REASON_DROPPED], $both['reasons']);
    }

    /** @return array<string, mixed> a summarize-run output of a step in the new form */
    private static function newSummary(int $sent, int $dropped): array
    {
        $rps = RpsAchievement::judgeStep(0.1, $sent, 400, $dropped);

        return [
            'mix' => 'even', 'scheme' => 'B', 'rps' => $rps, 'window_sec' => 400, 'window_epoch' => ['from' => 1.0, 'to' => 401.0],
            'db_utilization' => ['utilization' => 0.2, 'percent' => 20.0],
            'requests' => ['in_window' => 37, 'failed' => 0, 'dropped_iterations' => $dropped, 'sent_in_window' => $sent, 'finished_after_window' => 3, 'longest_after_window_ms' => 41_000.0, 'graceful_stop' => '600s'],
            'per_route' => [],
        ];
    }

    public function test_the_record_of_a_new_step_says_what_was_counted_and_what_happens_at_the_end_of_the_step(): void
    {
        $meta = ['scenario' => 's-1', 'scale' => 150000, 'rung' => 5, 'started_at' => 'a', 'ended_at' => 'b'];
        $r = Ladder::record($meta, self::newSummary(40, 0));

        $this->assertTrue($r['rps_valid']);
        $this->assertSame('sent', $r['rps_counted']);
        $this->assertSame([], $r['rps_invalid_reasons']);
        $this->assertSame(40, $r['requests_sent_in_window']);
        $this->assertSame(37, $r['requests_in_window'], 'the finished count stays in the record under its old name');
        $this->assertSame('600s', $r['end_of_step']['wait_for_running_requests']);
        $this->assertSame(3, $r['end_of_step']['requests_finished_after_window']);
        $this->assertSame(41_000.0, $r['end_of_step']['longest_after_window_ms']);
        $this->assertStringContainsString('outside the step\'s database utilization', $r['end_of_step']['note']);
        $this->assertFalse($r['decision']['stop']);
    }

    public function test_the_record_of_a_step_with_a_dropped_arrival_is_invalid_and_its_end_line_says_why(): void
    {
        $meta = ['scenario' => 's-1', 'scale' => 150000, 'rung' => 5, 'started_at' => 'a', 'ended_at' => 'b'];
        $r = Ladder::record($meta, self::newSummary(40, 2));

        $this->assertFalse($r['rps_valid']);
        $this->assertSame('invalid', $r['rps_status']);
        $this->assertSame([RpsAchievement::REASON_DROPPED], $r['rps_invalid_reasons']);
        $this->assertTrue($r['decision']['stop']);
        $line = Ladder::endLine($r);
        $this->assertStringContainsString('(invalid)', $line);
        $this->assertStringContainsString('STOP (' . RpsAchievement::REASON_DROPPED . ')', $line);
    }

    /** @return array<string, mixed> */
    private static function planFor(float $rps, int $duration, int $warmup): array
    {
        return ['rps' => $rps, 'duration_sec' => $duration, 'warmup_sec' => $warmup];
    }

    public function test_reading_a_step_again_shows_the_old_verdict_beside_the_new_one(): void
    {
        $plan = self::planFor(0.1, 460, 60);
        $record = ['scenario' => 's-1', 'rung' => 5, 'decided_rps' => 0.1, 'rps_valid' => false, 'requests_in_window' => 37];

        // as the third run's load tool wrote it: no sent count, so the finished count is read, and the verdict stays
        $old = StepReread::step($plan, ['measure_requests' => 37, 'dropped_iterations' => 0], $record);
        $this->assertSame('completed', $old['counted']);
        $this->assertTrue($old['same_count']);
        $this->assertTrue($old['same_verdict']);
        $this->assertFalse($old['new_valid']);

        // the same step as the new load tool would write it: 40 sent, 37 finished
        $new = StepReread::step($plan, ['measure_requests' => 37, 'measure_sent' => 40, 'dropped_iterations' => 0], $record);
        $this->assertSame('sent', $new['counted']);
        $this->assertTrue($new['new_valid']);
        $this->assertFalse($new['old_valid']);
        $this->assertFalse($new['same_verdict']);
        $this->assertSame(37, $new['old_count']);
        $this->assertSame(40, $new['new_count']);
    }

    /** @return list<array{string, string}> the second run's and the third run's scenario directories that were recorded */
    public static function recordedScenarioDirs(): array
    {
        $dirs = [];
        foreach (['recheck-ladder-b/r-[1-5]-*', 'ladder-300k/s-[1-5]-300000'] as $pattern) {
            foreach (glob(self::DATA . '/' . $pattern, GLOB_ONLYDIR) ?: [] as $dir) {
                if (!str_contains($dir, 'interrupted')) {
                    $dirs[basename($dir)] = [$dir, basename($dir)];
                }
            }
        }
        ksort($dirs);

        return array_values($dirs);
    }

    public function test_the_recorded_second_and_third_runs_are_found(): void
    {
        $this->assertCount(10, self::recordedScenarioDirs(), 'r-1 .. r-5 of the second run and s-1 .. s-5 of the third run are the fixtures; if they moved, move this test with them');
    }

    #[DataProvider('recordedScenarioDirs')]
    public function test_every_valid_step_of_the_second_and_third_runs_is_counted_the_same_and_stays_valid(string $dir, string $name): void
    {
        $rows = StepReread::scenarioDir($dir);
        $this->assertNotEmpty($rows, $name);
        foreach ($rows as $row) {
            $label = $name . ' rung ' . $row['rung'];
            $this->assertSame('completed', $row['counted'], "$label: these steps have no sent count");
            $this->assertTrue($row['same_count'], "$label: counted differently");
            $this->assertSame(0, $row['dropped_iterations'], "$label");
            $this->assertSame($row['old_valid'], $row['new_valid'], "$label: the verdict changed");
            $this->assertTrue($row['same_verdict'], "$label");
        }
        $this->assertContains(true, array_column($rows, 'old_valid'), "$name has valid steps");
    }

    public function test_the_reread_command_reads_a_recorded_scenario_and_counts_the_differences(): void
    {
        $cmd = [PHP_BINARY, __DIR__ . '/../../../../lab/load/ladder.php', 'reread', '--dir=' . self::DATA . '/ladder-300k/s-1-300000'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($p));
        $r = json_decode($out, true);
        $this->assertSame(5, $r['steps_read']);
        $this->assertSame(0, $r['steps_with_a_different_verdict']);
        $this->assertSame(0, $r['steps_with_a_different_count']);
        $this->assertFalse($r['steps'][4]['old_valid'], 'the fifth step is the one the third run called invalid');
    }

    public function test_the_reread_command_needs_a_directory(): void
    {
        $p = proc_open([PHP_BINARY, __DIR__ . '/../../../../lab/load/ladder.php', 'reread', '--dir=/no/such/dir'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $this->assertSame(2, proc_close($p));
    }

    public function test_reading_again_writes_nothing(): void
    {
        $dir = self::DATA . '/ladder-300k/s-1-300000';
        $before = self::fingerprint($dir);
        StepReread::scenarioDir($dir);
        $this->assertSame($before, self::fingerprint($dir));
    }

    /** @return array<string, string> */
    private static function fingerprint(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[$f->getPathname()] = $f->getMTime() . ':' . $f->getSize();
        }
        ksort($out);

        return $out;
    }
}
