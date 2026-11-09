<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Lab\Score\LadderMeasurements;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../lab/score/LadderMeasurements.php';

/** `poc/lab/load/ladder.php` and the app-CPU and status additions of `summarize-run.php`, run for real on small inputs. */
final class LadderCliTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ladder-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private static function tool(string $script, array $args): array
    {
        $cmd = array_merge([PHP_BINARY, __DIR__ . '/../../../../lab/load/' . $script], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($p), $out, $err];
    }

    private function routeList(): string
    {
        $selected = [];
        foreach (range(1, 8) as $i) {
            $selected[] = ['route' => "GET /r$i", 'rank' => $i];
        }
        file_put_contents($this->dir . '/list.json', json_encode(['selected' => $selected]));

        return $this->dir . '/list.json';
    }

    public function test_rungs_prints_the_13_steps_one_per_line(): void
    {
        [$code, $out] = self::tool('ladder.php', ['rungs']);
        $this->assertSame(0, $code);
        $this->assertSame(['0.05', '0.07', '0.1', '0.15', '0.2', '0.3', '0.45', '0.7', '1', '1.5', '2.2', '3.3', '5'], explode("\n", trim($out)));
    }

    public function test_scenario_prints_the_scale_and_the_mix_on_one_line_and_refuses_an_unknown_id(): void
    {
        $this->assertSame([0, "100000 first80\n"], array_slice(self::tool('ladder.php', ['scenario', 't-3', '--line']), 0, 2));
        $this->assertSame(2, self::tool('ladder.php', ['scenario', 'cal-1', '--line'])[0]);
    }

    public function test_make_plan_makes_a_plan_for_every_step_of_every_mix_the_ladder_uses(): void
    {
        foreach (['even', 'first80', 'last80'] as $mix) {
            foreach (['0.05', '0.07', '0.1', '0.15', '0.2', '0.3', '0.45', '0.7', '1', '1.5', '2.2', '3.3', '5'] as $rps) {
                [$code, , $err] = self::tool('make-plan.php', ['--routes=' . $this->routeList(), "--mix=$mix", "--rps=$rps", '--duration=360', '--warmup=60']);
                $this->assertSame(0, $code, "$mix at $rps: $err");
            }
        }
    }

    public function test_table_lists_every_step_and_marks_the_ones_outside_5_percent(): void
    {
        foreach ([0.05, 1.0] as $i => $rps) {
            mkdir($this->dir . '/dry/rung-0' . ($i + 1), 0777, true);
            [, $plan] = self::tool('make-plan.php', ['--routes=' . $this->routeList(), '--mix=even', "--rps=$rps", '--duration=360', '--warmup=60']);
            file_put_contents($this->dir . '/dry/rung-0' . ($i + 1) . '/plan.json', $plan);
        }
        [$code, $out] = self::tool('ladder.php', ['table', '--dir=' . $this->dir . '/dry']);

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/^1\s+0\.05\s+300s\s+8x1\/160s\s+16\s+15\.0\s+\+6\.7% OUTSIDE 5%/m', $out);
        $this->assertMatchesRegularExpression('/^2\s+1\s+300s\s+8x125\/1000s\s+296\s+300\.0\s+-1\.3% within 5%/m', $out);
        $this->assertStringContainsString('all 2 steps: 12 minutes of load', $out);
    }

    public function test_shape_and_stagger_give_the_length_of_a_step_and_each_routes_start(): void
    {
        $this->assertSame([0, "360 60\n"], array_slice(self::tool('ladder.php', ['shape', '--scheme=spec', '--rps=0.05']), 0, 2));
        $this->assertSame([0, "860 60\n"], array_slice(self::tool('ladder.php', ['shape', '--scheme=B', '--rps=0.05']), 0, 2));
        $this->assertSame(2, self::tool('ladder.php', ['shape', '--scheme=Z', '--rps=1'])[0]);

        [, $plan] = self::tool('make-plan.php', ['--routes=' . $this->routeList(), '--mix=even', '--rps=0.05', '--duration=360', '--warmup=60']);
        file_put_contents($this->dir . '/plan.json', $plan);
        [$code, $out] = self::tool('ladder.php', ['stagger', '--scheme=A', $this->dir . '/plan.json']);
        $this->assertSame(0, $code);
        $staggered = json_decode($out, true);
        $this->assertSame('A', $staggered['scheme']);
        $this->assertSame([2, 2, 2, 2, 2, 2, 2, 1], array_column($staggered['routes'], 'planned_window_requests'));
        mkdir($this->dir . '/a/rung-01', 0777, true);
        file_put_contents($this->dir . '/a/rung-01/plan.json', $out);
        [, $table] = self::tool('ladder.php', ['table', '--dir=' . $this->dir . '/a']);
        $this->assertMatchesRegularExpression('/^1\s+0\.05\s+300s\s+8x1\/160s\s+15\s+15\.0\s+\+0\.0% within 5%/m', $table);
    }

    /** @return string the path of a summarize-run output */
    private function runJson(float $util, bool $valid): string
    {
        $run = [
            'mix' => 'even',
            'rps' => ['target_rps' => 0.5, 'achieved_rps' => $valid ? 0.5 : 0.3, 'ratio' => 1.0, 'deviation' => 0.0, 'valid' => $valid, 'status' => $valid ? 'valid' : 'invalid'],
            'window_sec' => 300,
            'window_epoch' => ['from' => 1.0, 'to' => 301.0],
            'db_utilization' => ['utilization' => $util, 'percent' => $util * 100, 'delta_usage_usec' => 1.0, 'delta_sec' => 300.0, 'cpus' => 2],
            'app_cpu_utilization' => ['utilization' => 0.2, 'percent' => 20.0, 'delta_usage_usec' => 1.0, 'delta_sec' => 300.0, 'cpus' => 6],
            'requests' => ['in_window' => 150, 'failed' => 0, 'dropped_iterations' => 0, 'http_status' => ['200' => 150]],
            'per_route' => [],
        ];
        file_put_contents($this->dir . '/run.json', json_encode($run));

        return $this->dir . '/run.json';
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function record(float $util, bool $valid, int $rung = 3): array
    {
        return self::tool('ladder.php', [
            'record', '--scenario=t-4', "--rung=$rung", '--summary=' . $this->runJson($util, $valid),
            '--started=2026-11-10T10:00:00+09:00', '--ended=2026-11-10T10:06:00+09:00',
            '--prediction-file=poc/lab/predictions/p.json', '--prediction-commit=abc123', '--prediction-time=2026-11-10T09:00:00+09:00',
        ]);
    }

    public function test_record_exits_0_to_go_on_and_6_to_stop_and_writes_the_record_either_way(): void
    {
        [$code, $out, $err] = $this->record(0.5, true);
        $this->assertSame(0, $code);
        $r = json_decode($out, true);
        $this->assertSame('t-4', $r['scenario']);
        $this->assertSame(10000, $r['scale']);
        $this->assertSame('abc123', $r['prediction']['commit']);
        $this->assertStringContainsString('continue', $err);

        [$code, $out, $err] = $this->record(0.8, true);
        $this->assertSame(6, $code);
        $this->assertTrue(json_decode($out, true)['decision']['stop']);
        $this->assertStringContainsString('STOP', $err);

        [$code] = $this->record(0.3, false);
        $this->assertSame(6, $code);
    }

    public function test_decision_reads_the_stop_back_from_a_written_record(): void
    {
        file_put_contents($this->dir . '/stop.json', $this->record(0.9, true)[1]);
        file_put_contents($this->dir . '/go.json', $this->record(0.1, true)[1]);

        $this->assertSame(6, self::tool('ladder.php', ['decision', $this->dir . '/stop.json'])[0]);
        $this->assertSame(0, self::tool('ladder.php', ['decision', $this->dir . '/go.json'])[0]);
        $this->assertSame(2, self::tool('ladder.php', ['decision', $this->dir . '/nothing.json'])[0]);
    }

    public function test_collect_puts_the_steps_in_order_and_says_where_the_scenario_stopped(): void
    {
        foreach ([[1, 0.2, true], [2, 0.5, true], [3, 0.85, true]] as [$n, $util, $valid]) {
            mkdir($this->dir . "/s/rung-0$n", 0777, true);
            file_put_contents($this->dir . "/s/rung-0$n/record.json", $this->record($util, $valid, $n)[1]);
        }
        [$code, $out] = self::tool('ladder.php', ['collect', '--dir=' . $this->dir . '/s']);

        $this->assertSame(0, $code);
        $c = json_decode($out, true);
        $this->assertSame(3, $c['rungs_measured']);
        $this->assertSame(13, $c['rungs_in_ladder']);
        $this->assertSame(3, $c['stopped_after_rung']);
        $this->assertSame([1, 2, 3], array_column($c['rungs'], 'rung'));
    }

    public function test_the_collected_record_is_what_the_scoring_tool_reads(): void
    {
        foreach ([[1, 0.2, true], [2, 0.85, false]] as [$n, $util, $valid]) {
            mkdir($this->dir . "/s/rung-0$n", 0777, true);
            file_put_contents($this->dir . "/s/rung-0$n/record.json", $this->record($util, $valid, $n)[1]);
        }
        $ladder = json_decode(self::tool('ladder.php', ['collect', '--dir=' . $this->dir . '/s'])[1], true);

        $this->assertSame(
            ['t-4' => [['rps' => 0.5, 'valid' => true, 'utilization' => 0.2], ['rps' => 0.5, 'valid' => false, 'utilization' => 0.85]]],
            LadderMeasurements::fromLadders([$ladder]),
        );
    }

    public function test_summarize_run_adds_the_app_cpu_over_6_cpus_and_passes_the_status_counts_through(): void
    {
        [, $plan] = self::tool('make-plan.php', ['--routes=' . $this->routeList(), '--mix=even', '--rps=1', '--duration=360', '--warmup=60']);
        file_put_contents($this->dir . '/plan.json', $plan);
        file_put_contents($this->dir . '/k6.json', json_encode([
            'scenario_start_ms' => 1_000_000_000_000,
            'measure_requests' => 300, 'measure_failed' => 2, 'measure_ok' => 298, 'dropped_iterations' => 4,
            'http_status' => ['200' => 296, '503' => 2, 'other' => 2],
            'per_route' => array_fill_keys(array_map(static fn (int $i): string => "GET /r$i", range(1, 8)), 37),
        ]));
        $db = $app = '';
        for ($t = 999_999_990; $t <= 1_000_000_400; $t += 5) {
            $db .= $t . ' ' . (($t - 999_999_990) * 1_000_000) . "\n";          // 1 of 2 CPUs: 50%
            $app .= $t . ' ' . (($t - 999_999_990) * 3_000_000) . "\n";         // 3 of 6 CPUs: 50%
        }
        file_put_contents($this->dir . '/cpu.txt', $db);
        file_put_contents($this->dir . '/app.txt', $app);

        [$code, $out] = self::tool('summarize-run.php', ['--plan=' . $this->dir . '/plan.json', '--k6-summary=' . $this->dir . '/k6.json', '--samples=' . $this->dir . '/cpu.txt', '--app-samples=' . $this->dir . '/app.txt']);
        $this->assertSame(0, $code);
        $r = json_decode($out, true);
        $this->assertEqualsWithDelta(50.0, $r['db_utilization']['percent'], 1e-6);
        $this->assertEqualsWithDelta(50.0, $r['app_cpu_utilization']['percent'], 1e-6);
        $this->assertSame(6, $r['app_cpu_utilization']['cpus']);
        $this->assertSame(['200' => 296, '503' => 2, 'other' => 2], $r['requests']['http_status']);
    }

    public function test_summarize_run_without_the_new_options_gives_the_same_shape_as_before(): void
    {
        [, $plan] = self::tool('make-plan.php', ['--routes=' . $this->routeList(), '--mix=even', '--rps=1', '--duration=360', '--warmup=60']);
        file_put_contents($this->dir . '/plan.json', $plan);
        file_put_contents($this->dir . '/k6.json', json_encode(['scenario_start_ms' => 1_000_000_000_000, 'measure_requests' => 300, 'measure_failed' => 0, 'dropped_iterations' => 0, 'per_route' => []]));
        $db = '';
        for ($t = 999_999_990; $t <= 1_000_000_400; $t += 5) {
            $db .= $t . ' ' . (($t - 999_999_990) * 1_000_000) . "\n";
        }
        file_put_contents($this->dir . '/cpu.txt', $db);
        [$code, $out] = self::tool('summarize-run.php', ['--plan=' . $this->dir . '/plan.json', '--k6-summary=' . $this->dir . '/k6.json', '--samples=' . $this->dir . '/cpu.txt']);
        $this->assertSame(0, $code);
        $r = json_decode($out, true);
        $this->assertArrayNotHasKey('app_cpu_utilization', $r);
        $this->assertArrayNotHasKey('http_status', $r['requests']);
    }
}
