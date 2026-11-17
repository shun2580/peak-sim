<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\Ladder;
use PeakSim\Runtime\LabLoad\RouteMix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The count of a scenario's tables can come from the prediction file (a prediction made at another count than the table of
 * `Ladder::SCENARIOS`, such as B at 90,000), and nothing else of the scenario moves: the mix, the steps and the table's own counts
 * (300,000 for s-*) stay what they were when no count is given.
 */
final class LadderScaleFromPredictionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ladder-scale-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private static function tool(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, __DIR__ . '/../../../../lab/load/ladder.php'], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($p), $out, $err];
    }

    public function test_without_a_count_every_scenario_keeps_the_count_of_its_table(): void
    {
        $this->assertSame(['id' => 's-1', 'scale' => 300000, 'multiple' => 30, 'mix' => RouteMix::EVEN], Ladder::scenario('s-1'));
        $this->assertSame(['id' => 's-1', 'scale' => 300000, 'multiple' => 30, 'mix' => RouteMix::EVEN], Ladder::scenario('s-1', null));
        $this->assertSame(10000, Ladder::scenario('t-4')['scale']);
        $this->assertSame(50000, Ladder::scenario('r-1')['scale']);
    }

    public function test_the_count_of_a_prediction_gives_that_count_and_leaves_the_mix_alone(): void
    {
        $this->assertSame(['id' => 's-1', 'scale' => 90000, 'multiple' => 9, 'mix' => RouteMix::EVEN], Ladder::scenario('s-1', 90000));
        $this->assertSame(['id' => 's-4', 'scale' => 150000, 'multiple' => 15, 'mix' => RouteMix::PLACES_1235], Ladder::scenario('s-4', 150000));
        $this->assertSame(['id' => 's-5', 'scale' => 300000, 'multiple' => 30, 'mix' => RouteMix::PLACES_1345], Ladder::scenario('s-5', 300000));
        // the steps do not depend on the count
        $this->assertSame(Ladder::rungsFor('s-1'), Ladder::RUNGS_S);
    }

    /** @return array<string, array{0: int}> */
    public static function badCounts(): array
    {
        return ['zero' => [0], 'negative' => [-90000], 'below N_0' => [9999], 'not a multiple' => [95000], 'one more' => [90001]];
    }

    #[DataProvider('badCounts')]
    public function test_a_count_that_is_not_a_whole_multiple_of_n0_is_refused(int $scale): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ladder::scenario('s-1', $scale);
    }

    public function test_an_unknown_scenario_is_refused_whatever_the_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ladder::scenario('s-6', 90000);
    }

    public function test_the_scenario_command_gives_90000_for_a_90000_prediction_and_300000_as_before(): void
    {
        $this->assertSame([0, "90000 even\n"], array_slice(self::tool(['scenario', 's-1', '--scale=90000', '--line']), 0, 2));
        $this->assertSame([0, "90000 places1235\n"], array_slice(self::tool(['scenario', 's-4', '--scale=90000', '--line']), 0, 2));
        $this->assertSame([0, "300000 even\n"], array_slice(self::tool(['scenario', 's-1', '--line']), 0, 2));
        $this->assertSame([0, "300000 even\n"], array_slice(self::tool(['scenario', 's-1', '--scale=300000', '--line']), 0, 2));
        $json = json_decode(self::tool(['scenario', 's-1', '--scale=90000'])[1], true);
        $this->assertSame(['id' => 's-1', 'scale' => 90000, 'multiple' => 9, 'mix' => 'even'], $json);
    }

    public function test_the_scenario_command_refuses_a_bad_count_and_a_count_with_a_trial(): void
    {
        foreach (['--scale=95000', '--scale=0', '--scale=-1', '--scale=abc', '--scale=', '--scale', '--scale=9e4', '--scale=09000'] as $bad) {
            $this->assertSame(2, self::tool(['scenario', 's-1', $bad, '--line'])[0], $bad);
        }
        $this->assertSame(2, self::tool(['scenario', 'cal-2', '--trial', '--scale=10000', '--line'])[0], 'a trial is always at N_0');
        $this->assertSame(2, self::tool(['scenario', 's-6', '--scale=90000', '--line'])[0]);
    }

    public function test_a_record_carries_the_count_it_was_measured_at_and_one_with_no_count_the_tables(): void
    {
        file_put_contents($this->dir . '/run.json', json_encode([
            'mix' => 'even',
            'rps' => ['target_rps' => 0.5, 'achieved_rps' => 0.5, 'ratio' => 1.0, 'deviation' => 0.0, 'valid' => true, 'status' => 'valid'],
            'window_sec' => 300,
            'window_epoch' => ['from' => 1.0, 'to' => 301.0],
            'db_utilization' => ['utilization' => 0.4, 'percent' => 40.0, 'delta_usage_usec' => 1.0, 'delta_sec' => 300.0, 'cpus' => 2],
            'app_cpu_utilization' => ['utilization' => 0.2, 'percent' => 20.0, 'delta_usage_usec' => 1.0, 'delta_sec' => 300.0, 'cpus' => 6],
            'requests' => ['in_window' => 150, 'failed' => 0, 'dropped_iterations' => 0, 'http_status' => ['200' => 150]],
            'per_route' => [],
        ]));
        $common = ['record', '--scenario=s-1', '--rung=1', '--summary=' . $this->dir . '/run.json', '--started=2026-11-17T10:00:00+09:00', '--ended=2026-11-17T10:06:00+09:00'];

        [$code, $rec] = self::tool(array_merge($common, ['--scale=90000']));
        $this->assertSame(0, $code);
        $r = json_decode($rec, true);
        $this->assertSame(90000, $r['scale']);
        $this->assertSame('even', $r['mix']);

        [$code, $rec] = self::tool($common);
        $this->assertSame(0, $code);
        $this->assertSame(300000, json_decode($rec, true)['scale']);

        $this->assertSame(2, self::tool(array_merge($common, ['--scale=95000']))[0]);
        $this->assertSame(2, self::tool(array_merge(['record', '--trial=cal-2', '--rung=1', '--summary=' . $this->dir . '/run.json', '--started=a', '--ended=b'], ['--scale=10000']))[0], 'a trial takes no count');
    }
}
