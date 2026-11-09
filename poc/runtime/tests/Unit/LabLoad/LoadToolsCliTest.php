<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PHPUnit\Framework\TestCase;

/** The two command-line tools of poc/lab/load, run for real on small inputs: the plan, and the record of one scenario. */
final class LoadToolsCliTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/labload-' . bin2hex(random_bytes(4));
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

    public function test_make_plan_reads_the_list_in_its_order_and_writes_the_rates(): void
    {
        [$code, $out] = self::tool('make-plan.php', ['--routes=' . $this->routeList(), '--mix=last80', '--rps=40']);

        $this->assertSame(0, $code);
        $plan = json_decode($out, true);
        $this->assertSame(['GET /r1', 'GET /r2', 'GET /r3', 'GET /r4', 'GET /r5', 'GET /r6', 'GET /r7', 'GET /r8'], array_column($plan['routes'], 'route'));
        $this->assertSame([2, 2, 2, 2, 8, 8, 8, 8], array_column($plan['routes'], 'rate'));
    }

    public function test_make_plan_refuses_a_bad_rps_or_mix_with_exit_2(): void
    {
        $this->assertSame(2, self::tool('make-plan.php', ['--routes=' . $this->routeList(), '--mix=even', '--rps=0'])[0]);
        $this->assertSame(2, self::tool('make-plan.php', ['--routes=' . $this->routeList(), '--mix=odd', '--rps=10'])[0]);
        $this->assertSame(2, self::tool('make-plan.php', ['--routes=/no/such/file', '--mix=even', '--rps=10'])[0]);
    }

    /** @return array{0: string, 1: string} paths of the plan and the samples for an even mix of 80 rps */
    private function scenarioFiles(int $requestsInWindow, ?string $samples = null): array
    {
        [, $plan] = self::tool('make-plan.php', ['--routes=' . $this->routeList(), '--mix=even', '--rps=80']);
        file_put_contents($this->dir . '/plan.json', $plan);
        $perRoute = [];
        foreach (range(1, 8) as $i) {
            $perRoute["GET /r$i"] = intdiv($requestsInWindow, 8);
        }
        file_put_contents($this->dir . '/k6.json', json_encode([
            'scenario_start_ms' => 1_000_000_000_000, // epoch second 1,000,000,000
            'measure_requests' => $requestsInWindow,
            'measure_failed' => 0,
            'dropped_iterations' => 0,
            'per_route' => $perRoute,
        ]));
        // 1 CPU of 2 busy for the whole run: usage grows by 1,000,000 usec per second, from second 1,000,000,000 - 10 to the end + 10.
        $lines = '';
        for ($t = 999_999_990; $t <= 1_000_000_610; $t += 5) {
            $lines .= $t . ' ' . (($t - 999_999_990) * 1_000_000) . "\n";
        }
        file_put_contents($this->dir . '/samples.txt', $samples ?? $lines);

        return [$this->dir . '/plan.json', $this->dir . '/samples.txt'];
    }

    public function test_summarize_run_reports_a_valid_rps_and_a_50_percent_utilization(): void
    {
        [$plan, $samples] = $this->scenarioFiles(80 * 480);
        [$code, $out] = self::tool('summarize-run.php', ['--plan=' . $plan, '--k6-summary=' . $this->dir . '/k6.json', '--samples=' . $samples]);

        $this->assertSame(0, $code);
        $r = json_decode($out, true);
        $this->assertSame('valid', $r['rps']['status']);
        $this->assertEqualsWithDelta(80.0, $r['rps']['achieved_rps'], 1e-9);
        $this->assertSame(480, $r['window_sec']);
        $this->assertEqualsWithDelta(50.0, $r['db_utilization']['percent'], 1e-6);
        $this->assertCount(8, $r['per_route']);
    }

    public function test_summarize_run_marks_a_scenario_that_missed_its_rps_invalid_and_still_reports_it(): void
    {
        [$plan, $samples] = $this->scenarioFiles(60 * 480);
        [$code, $out] = self::tool('summarize-run.php', ['--plan=' . $plan, '--k6-summary=' . $this->dir . '/k6.json', '--samples=' . $samples]);

        $this->assertSame(0, $code);
        $this->assertSame('invalid', json_decode($out, true)['rps']['status']);
    }

    public function test_summarize_run_gives_no_utilization_when_the_samples_do_not_cover_the_window(): void
    {
        [$plan, $samples] = $this->scenarioFiles(80 * 480, "999999990 0\n1000000100 100000000\n");
        [$code, $out, $err] = self::tool('summarize-run.php', ['--plan=' . $plan, '--k6-summary=' . $this->dir . '/k6.json', '--samples=' . $samples]);

        $this->assertSame(3, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('cannot be worked out', $err);
    }
}
