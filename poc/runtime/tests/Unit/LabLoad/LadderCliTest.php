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

    public function test_the_trial_has_one_step_at_r0_and_a_calibration_mix_at_n0_and_refuses_the_prediction_scenarios(): void
    {
        $this->assertSame([0, "1\n"], array_slice(self::tool('ladder.php', ['rungs', '--trial']), 0, 2));
        $this->assertSame([0, "10000 first80\n"], array_slice(self::tool('ladder.php', ['scenario', 'cal-2', '--trial', '--line']), 0, 2));
        $this->assertSame([0, "10000 odd80\n"], array_slice(self::tool('ladder.php', ['scenario', 'cal-3', '--trial', '--line']), 0, 2));
        foreach (['t-4', 't-1', 'even', 'cal-1'] as $name) {
            $this->assertSame(2, self::tool('ladder.php', ['scenario', $name, '--trial', '--line'])[0], $name);
        }
        $this->assertSame(2, self::tool('ladder.php', ['scenario', 'cal-2', '--line'])[0], 'a trial name without --trial is no scenario');
    }

    public function test_the_recheck_scenarios_are_read_and_the_order_has_both_sets_apart(): void
    {
        $this->assertSame([0, "50000 even\n"], array_slice(self::tool('ladder.php', ['scenario', 'r-1', '--line']), 0, 2));
        $this->assertSame([0, "50000 first80\n"], array_slice(self::tool('ladder.php', ['scenario', 'r-2', '--line']), 0, 2));
        $this->assertSame([0, "200000 even\n"], array_slice(self::tool('ladder.php', ['scenario', 'r-3', '--line']), 0, 2));
        $this->assertSame([0, "200000 first80\n"], array_slice(self::tool('ladder.php', ['scenario', 'r-4', '--line']), 0, 2));
        $this->assertSame([0, "200000 last80\n"], array_slice(self::tool('ladder.php', ['scenario', 'r-5', '--line']), 0, 2));
        $this->assertSame(2, self::tool('ladder.php', ['scenario', 'r-6', '--line'])[0]);
        $this->assertSame(2, self::tool('ladder.php', ['scenario', 'r-1', '--trial', '--line'])[0], 'a re-check scenario is never a trial');

        $this->assertSame([['scale' => 10000, 'scenarios' => ['t-4']], ['scale' => 30000, 'scenarios' => ['t-1']], ['scale' => 100000, 'scenarios' => ['t-2', 't-3', 't-5']]], json_decode(self::tool('ladder.php', ['order'])[1], true));
        $this->assertSame([['scale' => 50000, 'scenarios' => ['r-1', 'r-2']], ['scale' => 200000, 'scenarios' => ['r-3', 'r-4', 'r-5']]], json_decode(self::tool('ladder.php', ['order', '--set=r'])[1], true));
        $this->assertSame(2, self::tool('ladder.php', ['order', '--set=x'])[0]);
    }

    public function test_each_recheck_scenario_uses_one_of_the_three_mixes_the_ladder_already_plans_for(): void
    {
        // the plans of these three mixes at every step are made by test_make_plan_makes_a_plan_for_every_step_of_every_mix_the_ladder_uses
        foreach (['r-1' => 'even', 'r-2' => 'first80', 'r-3' => 'even', 'r-4' => 'first80', 'r-5' => 'last80'] as $id => $mix) {
            $this->assertSame($mix, trim(explode(' ', self::tool('ladder.php', ['scenario', $id, '--line'])[1])[1]), $id);
        }
    }

    public function test_rungs_of_a_third_backtest_scenario_are_the_15_steps_and_every_other_id_or_none_keeps_the_13(): void
    {
        $thirteen = ['0.05', '0.07', '0.1', '0.15', '0.2', '0.3', '0.45', '0.7', '1', '1.5', '2.2', '3.3', '5'];
        foreach (['s-1', 's-2', 's-3', 's-4', 's-5'] as $id) {
            [$code, $out] = self::tool('ladder.php', ['rungs', "--scenario=$id"]);
            $this->assertSame(0, $code, $id);
            $this->assertSame(array_merge(['0.025', '0.035'], $thirteen), explode("\n", trim($out)), $id);
        }
        foreach (['t-1', 't-4', 'r-1', 'r-5'] as $id) {
            $this->assertSame($thirteen, explode("\n", trim(self::tool('ladder.php', ['rungs', "--scenario=$id"])[1])), $id);
        }
        $this->assertSame($thirteen, explode("\n", trim(self::tool('ladder.php', ['rungs'])[1])), 'no option: unchanged');
        $this->assertSame(2, self::tool('ladder.php', ['rungs', '--scenario=s-6'])[0], 'a name that is no scenario never gets the 13');
        $this->assertSame(['1'], explode("\n", trim(self::tool('ladder.php', ['rungs', '--trial'])[1])), 'a trial keeps its one step');
    }

    public function test_the_third_backtest_scenarios_are_read_through_the_same_door_as_the_others(): void
    {
        $this->assertSame([0, "300000 even\n"], array_slice(self::tool('ladder.php', ['scenario', 's-1', '--line']), 0, 2));
        $this->assertSame([0, "300000 first80\n"], array_slice(self::tool('ladder.php', ['scenario', 's-2', '--line']), 0, 2));
        $this->assertSame([0, "300000 last80\n"], array_slice(self::tool('ladder.php', ['scenario', 's-3', '--line']), 0, 2));
        $this->assertSame([0, "300000 places1235\n"], array_slice(self::tool('ladder.php', ['scenario', 's-4', '--line']), 0, 2));
        $this->assertSame([0, "300000 places1345\n"], array_slice(self::tool('ladder.php', ['scenario', 's-5', '--line']), 0, 2));
        $this->assertSame(2, self::tool('ladder.php', ['scenario', 's-6', '--line'])[0]);
        $this->assertSame(2, self::tool('ladder.php', ['scenario', 's-1', '--trial', '--line'])[0], 'a third-backtest scenario is never a trial');
        $this->assertSame([['scale' => 300000, 'scenarios' => ['s-1', 's-2', 's-3', 's-4', 's-5']]], json_decode(self::tool('ladder.php', ['order', '--set=s'])[1], true));
        // t and r are what they were
        $this->assertSame([['scale' => 10000, 'scenarios' => ['t-4']], ['scale' => 30000, 'scenarios' => ['t-1']], ['scale' => 100000, 'scenarios' => ['t-2', 't-3', 't-5']]], json_decode(self::tool('ladder.php', ['order'])[1], true));
    }

    public function test_make_plan_makes_a_plan_for_even80_at_every_one_of_the_15_steps_and_gives_the_even_places_80_percent(): void
    {
        foreach (['0.025', '0.035', '0.05', '0.07', '0.1', '0.15', '0.2', '0.3', '0.45', '0.7', '1', '1.5', '2.2', '3.3', '5'] as $rps) {
            [$code, $out, $err] = self::tool('make-plan.php', ['--routes=' . $this->routeList(), '--mix=even80', "--rps=$rps", '--duration=360', '--warmup=60']);
            $this->assertSame(0, $code, "even80 at $rps: $err");
            $plan = json_decode($out, true);
            $byRoute = array_column($plan['routes'], 'rps', 'route');
            $this->assertEqualsWithDelta(0.8 * (float) $rps, $byRoute['GET /r2'] + $byRoute['GET /r4'] + $byRoute['GET /r6'] + $byRoute['GET /r8'], 1e-9, "at $rps");
        }
    }

    public function test_make_plan_makes_a_plan_for_the_two_place_mixes_at_every_one_of_the_15_steps_and_gives_the_named_places_80_percent(): void
    {
        $heavy = ['places1235' => ['GET /r1', 'GET /r2', 'GET /r3', 'GET /r5'], 'places1345' => ['GET /r1', 'GET /r3', 'GET /r4', 'GET /r5']];
        foreach ($heavy as $mix => $places) {
            foreach (['0.025', '0.035', '0.05', '0.07', '0.1', '0.15', '0.2', '0.3', '0.45', '0.7', '1', '1.5', '2.2', '3.3', '5'] as $rps) {
                [$code, $out, $err] = self::tool('make-plan.php', ['--routes=' . $this->routeList(), "--mix=$mix", "--rps=$rps", '--duration=360', '--warmup=60']);
                $this->assertSame(0, $code, "$mix at $rps: $err");
                $byRoute = array_column(json_decode($out, true)['routes'], 'rps', 'route');
                $this->assertEqualsWithDelta(0.8 * (float) $rps, array_sum(array_intersect_key($byRoute, array_flip($places))), 1e-9, "$mix at $rps");
            }
        }
    }

    public function test_collect_of_a_third_backtest_scenario_says_the_ladder_has_15_steps_and_of_a_first_one_13(): void
    {
        foreach (['s-3' => 15, 'r-1' => 13, 't-4' => 13] as $id => $steps) {
            $dir = $this->dir . '/c-' . $id;
            mkdir($dir . '/rung-01', 0777, true);
            $record = json_decode($this->record(0.2, true, 1)[1], true);
            $record['scenario'] = $id;
            file_put_contents($dir . '/rung-01/record.json', json_encode($record));
            $c = json_decode(self::tool('ladder.php', ['collect', '--dir=' . $dir])[1], true);
            $this->assertSame($steps, $c['rungs_in_ladder'], $id);
        }
    }

    /** @return array<string, string> the three files `settings` reads */
    private function settingsFiles(string $maxExecutionTime = '0', string $readTimeout = '600s', ?string $memoryLimit = null): array
    {
        file_put_contents($this->dir . '/nginx.txt', "http {\n    server {\n        location / {\n            fastcgi_read_timeout $readTimeout;\n        }\n    }\n}\n");
        file_put_contents($this->dir . '/fpm.txt', "[10-Nov-2026 10:39:21] NOTICE: \trequest_terminate_timeout = 0s\n");
        file_put_contents($this->dir . '/ini.txt', json_encode(['sapi' => 'fpm-fcgi', 'max_execution_time' => $maxExecutionTime, 'memory_limit' => $memoryLimit ?? ($maxExecutionTime === '0' ? '4G' : '512M')]) . "\n");

        return ['--nginx=' . $this->dir . '/nginx.txt', '--fpm=' . $this->dir . '/fpm.txt', '--ini=' . $this->dir . '/ini.txt'];
    }

    public function test_settings_gives_the_three_limits_and_a_record_made_with_it_carries_them(): void
    {
        [$code, $out] = self::tool('ladder.php', array_merge(['settings'], $this->settingsFiles(), ['--k6-timeout=600s']));
        $this->assertSame(0, $code);
        $s = json_decode($out, true);
        $this->assertSame('0', $s['php_max_execution_time']);
        $this->assertSame('600s', $s['nginx_fastcgi_read_timeout']);
        $this->assertSame('600s', $s['k6_request_timeout']);

        file_put_contents($this->dir . '/settings.json', $out);
        $run = json_decode((string) file_get_contents($this->runJson(0.4, true)), true);
        $run['requests']['http_status_by_route'] = ['GET /r1' => ['200' => 10, '500' => 1]];
        $run['requests']['request_timeout'] = '600s';
        file_put_contents($this->dir . '/run.json', json_encode($run));
        [$code, $rec] = self::tool('ladder.php', [
            'record', '--scenario=r-1', '--rung=2', '--summary=' . $this->dir . '/run.json', '--settings=' . $this->dir . '/settings.json',
            '--started=2026-11-11T10:00:00+09:00', '--ended=2026-11-11T10:06:00+09:00',
            '--prediction-file=poc/lab/predictions/p.json', '--prediction-commit=abc123', '--prediction-time=2026-11-11T09:00:00+09:00',
        ]);
        $this->assertSame(0, $code);
        $r = json_decode($rec, true);
        $this->assertSame('r-1', $r['scenario']);
        $this->assertSame(50000, $r['scale']);
        $this->assertSame('0', $r['settings']['php_max_execution_time']);
        $this->assertSame('600s', $r['settings']['k6_request_timeout_seen_by_k6']);
        $this->assertSame(['GET /r1' => ['200' => 10, '500' => 1]], $r['http_status_by_route']);
    }

    public function test_settings_without_a_k6_timeout_says_the_default_applied_and_a_missing_file_or_value_stops_it(): void
    {
        [$code, $out] = self::tool('ladder.php', array_merge(['settings'], $this->settingsFiles('30', '120s')));
        $this->assertSame(0, $code);
        $this->assertNull(json_decode($out, true)['k6_request_timeout']);

        $this->assertSame(2, self::tool('ladder.php', ['settings', '--nginx=/no/such', '--fpm=/no/such', '--ini=/no/such'])[0]);
        file_put_contents($this->dir . '/ini.txt', 'cannot connect: Connection refused');
        [$code, , $err] = self::tool('ladder.php', ['settings', '--nginx=' . $this->dir . '/nginx.txt', '--fpm=' . $this->dir . '/fpm.txt', '--ini=' . $this->dir . '/ini.txt']);
        $this->assertSame(3, $code);
        $this->assertStringContainsString('max_execution_time', $err);
    }

    public function test_settings_require_unlimited_passes_a_lab_set_for_b_and_stops_a_lab_left_at_the_default_with_exit_4(): void
    {
        $b = array_merge(['settings'], $this->settingsFiles('0', '600s'), ['--k6-timeout=600s', '--require=unlimited']);
        [$code, $out, $err] = self::tool('ladder.php', $b);
        $this->assertSame([0, ''], [$code, $err]);
        $this->assertSame('0', json_decode($out, true)['php_max_execution_time']);

        // the lab as it is when nobody switched it: PHP 30, nginx 120s
        [$code, $out, $err] = self::tool('ladder.php', array_merge(['settings'], $this->settingsFiles('30', '120s'), ['--k6-timeout=600s', '--require=unlimited']));
        $this->assertSame(4, $code);
        $this->assertStringContainsString('max_execution_time', $err);
        $this->assertStringContainsString('fastcgi_read_timeout', $err);
        // the readings are still printed, so that what was read is kept with the refusal
        $this->assertSame('30', json_decode($out, true)['php_max_execution_time']);
    }

    public function test_settings_require_unlimited_without_the_k6_timeout_stops_and_so_does_an_unknown_requirement(): void
    {
        $files = $this->settingsFiles('0', '600s');
        $this->assertSame(4, self::tool('ladder.php', array_merge(['settings'], $files, ['--require=unlimited']))[0]);
        $this->assertSame(2, self::tool('ladder.php', array_merge(['settings'], $files, ['--k6-timeout=600s', '--require=sometimes']))[0]);
        // without --require nothing is judged (what t-1 .. t-5 and a trial do): a lab at the default is read, exit 0
        $this->assertSame(0, self::tool('ladder.php', array_merge(['settings'], $this->settingsFiles('30', '120s')))[0]);
    }

    public function test_settings_require_limited_wants_30(): void
    {
        $this->assertSame(0, self::tool('ladder.php', array_merge(['settings'], $this->settingsFiles('30', '120s'), ['--require=limited']))[0]);
        [$code, , $err] = self::tool('ladder.php', array_merge(['settings'], $this->settingsFiles('0', '600s'), ['--require=limited']));
        $this->assertSame(4, $code);
        $this->assertStringContainsString('max_execution_time', $err);
    }

    public function test_settings_require_checks_the_memory_limit_too_4g_for_b_and_512m_for_the_mark(): void
    {
        // B with the time limit off but the memory limit left at 512M: refused, and the refusal names memory_limit
        [$code, $out, $err] = self::tool('ladder.php', array_merge(['settings'], $this->settingsFiles('0', '600s', '512M'), ['--k6-timeout=600s', '--require=unlimited']));
        $this->assertSame(4, $code);
        $this->assertStringContainsString('memory_limit', $err);
        $this->assertSame('512M', json_decode($out, true)['php_memory_limit']);
        // the mark with the time limit back on but the memory limit left at 4G
        [$code, , $err] = self::tool('ladder.php', array_merge(['settings'], $this->settingsFiles('30', '120s', '4G'), ['--require=limited']));
        $this->assertSame(4, $code);
        $this->assertStringContainsString('memory_limit', $err);
        // both set as they should be: B 0 / 4G, the mark 30 / 512M
        [$code, $out, $err] = self::tool('ladder.php', array_merge(['settings'], $this->settingsFiles('0', '600s', '4G'), ['--k6-timeout=600s', '--require=unlimited']));
        $this->assertSame([0, ''], [$code, $err]);
        $this->assertSame('4G', json_decode($out, true)['php_memory_limit']);
        $this->assertSame(0, self::tool('ladder.php', array_merge(['settings'], $this->settingsFiles('30', '120s', '512M'), ['--require=limited']))[0]);
    }

    public function test_settings_require_with_an_unreadable_limit_still_stops_with_exit_3_first(): void
    {
        $files = $this->settingsFiles('0', '600s');
        file_put_contents($this->dir . '/ini.txt', 'cannot connect: Connection refused');
        $this->assertSame(3, self::tool('ladder.php', array_merge(['settings'], $files, ['--k6-timeout=600s', '--require=unlimited']))[0]);
        $this->assertSame(3, self::tool('ladder.php', array_merge(['settings'], $files, ['--require=limited']))[0]);
    }

    public function test_a_trial_record_is_written_with_trial_true_and_no_prediction_and_refuses_one(): void
    {
        $common = ['--rung=1', '--summary=' . $this->runJson(0.4, true), '--started=2026-11-10T10:00:00+09:00', '--ended=2026-11-10T10:06:00+09:00'];
        [$code, $out] = self::tool('ladder.php', array_merge(['record', '--trial=cal-3'], $common));
        $this->assertSame(0, $code);
        $r = json_decode($out, true);
        $this->assertSame('trial-cal-3', $r['scenario']);
        $this->assertSame(10000, $r['scale']);
        $this->assertTrue($r['trial']);
        $this->assertNull($r['prediction']);
        $this->assertSame(2, self::tool('ladder.php', array_merge(['record', '--trial=cal-3', '--prediction-file=x'], $common))[0]);
        $this->assertSame(2, self::tool('ladder.php', array_merge(['record', '--trial=cal-3', '--scenario=t-4'], $common))[0]);
        $this->assertSame(2, self::tool('ladder.php', array_merge(['record', '--trial=t-4'], $common))[0]);
        $this->assertSame(2, self::tool('ladder.php', array_merge(['record'], $common))[0], 'neither a scenario nor a trial');
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

    public function test_summarize_run_carries_the_status_of_each_route_and_the_timeout_k6_was_given_and_leaves_them_out_when_k6_did_not_write_them(): void
    {
        [, $plan] = self::tool('make-plan.php', ['--routes=' . $this->routeList(), '--mix=even', '--rps=1', '--duration=360', '--warmup=60']);
        file_put_contents($this->dir . '/plan.json', $plan);
        $db = '';
        for ($t = 999_999_990; $t <= 1_000_000_400; $t += 5) {
            $db .= $t . ' ' . (($t - 999_999_990) * 1_000_000) . "\n";
        }
        file_put_contents($this->dir . '/cpu.txt', $db);
        $k6 = ['scenario_start_ms' => 1_000_000_000_000, 'measure_requests' => 300, 'measure_failed' => 1, 'dropped_iterations' => 0, 'per_route' => [],
            'http_status' => ['200' => 299, '500' => 1], 'http_status_by_route' => ['GET /r1' => ['200' => 37], 'GET /r2' => ['200' => 36, '500' => 1]], 'request_timeout' => '600s'];
        file_put_contents($this->dir . '/k6.json', json_encode($k6));
        $args = ['--plan=' . $this->dir . '/plan.json', '--k6-summary=' . $this->dir . '/k6.json', '--samples=' . $this->dir . '/cpu.txt'];

        $r = json_decode(self::tool('summarize-run.php', $args)[1], true);
        $this->assertSame(['GET /r1' => ['200' => 37], 'GET /r2' => ['200' => 36, '500' => 1]], $r['requests']['http_status_by_route']);
        $this->assertSame('600s', $r['requests']['request_timeout']);
        $this->assertSame(['200' => 299, '500' => 1], $r['requests']['http_status'], 'the overall breakdown is as it was');

        // k6 gave no timeout: its own null is kept (the field says "not given"), and a summary from before has neither field
        $k6['request_timeout'] = null;
        file_put_contents($this->dir . '/k6.json', json_encode($k6));
        $this->assertNull(json_decode(self::tool('summarize-run.php', $args)[1], true)['requests']['request_timeout']);
        unset($k6['request_timeout'], $k6['http_status_by_route']);
        file_put_contents($this->dir . '/k6.json', json_encode($k6));
        $r = json_decode(self::tool('summarize-run.php', $args)[1], true);
        $this->assertArrayNotHasKey('http_status_by_route', $r['requests']);
        $this->assertArrayNotHasKey('request_timeout', $r['requests']);
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
