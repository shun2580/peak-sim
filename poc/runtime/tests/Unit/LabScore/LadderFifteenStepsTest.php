<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabScore;

use PeakSim\Runtime\LabLoad\Ladder;
use PHPUnit\Framework\TestCase;

/**
 * The scoring tool on the 15-step ladders of the third backtest (s-1 .. s-5, simulator-spec.md section 7.4.2), checked with made-up
 * inputs only (no lab, no database) before any prediction of those scenarios is committed. The tool reads the steps from the
 * prediction file and never holds a number of steps or a ladder of its own, so 15 steps must go through as 13 do. Five shapes are put
 * through the real command (`score-ladder.php --prediction --ladder`): a ladder that crosses 80% (interpolated), one that is extended
 * after an invalid step at or above 80%, one that stops below 80% on an invalid step, one with a single valid step, and one whose
 * 0.035 step comes out at exactly the edge of the 5% check. The scores of the first two backtests are pinned byte for byte.
 *
 * Nothing here is a measurement and nothing here is a prediction of the lab: the utilizations and limits are numbers chosen by hand
 * so that the expected score can be worked out on paper.
 */
final class LadderFifteenStepsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/score-fifteen-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/out', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,out/}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->dir . '/out');
        rmdir($this->dir);
    }

    /** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
    private function runCommand(string ...$args): array
    {
        $script = realpath(__DIR__ . '/../../../../lab/score/score-ladder.php');
        $this->assertNotFalse($script);
        $proc = proc_open([PHP_BINARY, $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }

    /**
     * A prediction file of the ladder (kind "lab-ladder-prediction") with the 15 steps of Ladder::RUNGS_S for each id, in the form the
     * real file has (the row keys of poc/lab/predictions/ladder-prediction.json). The numbers are made up: point 4 x rps, safe 0.05 above
     * it, naive twice the point; the predicted limit 0.2 rps.
     *
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     */
    private static function prediction(array $ids): array
    {
        $scenarios = [];
        foreach ($ids as $id) {
            $stages = [];
            foreach (Ladder::RUNGS_S as $rps) {
                $point = min(1.5, 4.0 * $rps);
                $stages[] = [
                    'rps_total' => $rps,
                    'rps' => ['GET /a' => $rps],
                    'utilization' => ['point' => $point, 'upper' => $point + 0.05],
                    'naive' => min(3.0, 2.0 * $point),
                    'over_threshold' => ['point' => $point > 0.8, 'upper' => $point + 0.05 > 0.8, 'naive' => 2.0 * $point > 0.8],
                ];
            }
            $scenarios[] = [
                'id' => $id,
                'stages' => $stages,
                'limit_rps' => [
                    'point' => ['status' => 'found', 'rps' => 0.2],
                    'safe' => ['status' => 'found', 'rps' => 0.19],
                    'naive' => ['status' => 'found', 'rps' => 0.1],
                ],
            ];
        }

        return ['schema_version' => '0.1', 'kind' => 'lab-ladder-prediction', 'resources' => ['cores' => 2.0, 'threshold' => 0.8],
            'stages_rps_total' => Ladder::RUNGS_S, 'scenarios' => $scenarios];
    }

    /**
     * A ladder.json (the record the measuring tool writes) for the steps measured: [decided rps, db utilization, rps_valid]. The
     * rps_ratio and achieved_rps are written as the measuring tool writes them; the scoring tool does not read them.
     *
     * @param list<array{0: float, 1: float, 2: bool}> $steps
     * @param array<string, float>                     $ratio rps (as a string key) => achieved / decided, 1.0 when not listed
     *
     * @return array<string, mixed>
     */
    private static function ladder(string $id, array $steps, array $ratio = []): array
    {
        $rungs = [];
        foreach ($steps as $i => [$rps, $util, $valid]) {
            $r = $ratio[(string) $rps] ?? 1.0;
            $rungs[] = ['scenario' => $id, 'rung' => $i + 1, 'decided_rps' => $rps, 'achieved_rps' => $rps * $r, 'rps_ratio' => $r,
                'rps_valid' => $valid, 'db_utilization' => $util];
        }

        return ['scenario' => $id, 'rungs' => $rungs];
    }

    /** @param array<string, array<string, mixed>> $ladders id => ladder record */
    private function score(array $ids, array $ladders): array
    {
        file_put_contents($this->dir . '/prediction.json', json_encode(self::prediction($ids), JSON_THROW_ON_ERROR));
        $args = ['--prediction=' . $this->dir . '/prediction.json', '--out=' . $this->dir . '/out'];
        foreach ($ladders as $id => $ladder) {
            file_put_contents($this->dir . '/ladder-' . $id . '.json', json_encode($ladder, JSON_THROW_ON_ERROR));
            $args[] = '--ladder=' . $this->dir . '/ladder-' . $id . '.json';
        }
        [$code, $stdout, $stderr] = $this->runCommand(...$args);
        $this->assertSame(0, $code, $stderr);

        return ['score' => json_decode((string) file_get_contents($this->dir . '/out/score.json'), true, 512, JSON_THROW_ON_ERROR), 'stdout' => $stdout];
    }

    /** The five shapes: s-1 crosses 80% (a), s-2 is extended (b), s-3 stops below 80% on an invalid step (c), s-4 has one valid step (d), s-5 has 0.035 at the 5% edge (e). */
    private static function fiveShapes(): array
    {
        return [
            // (a) the lowest step 0.025 already at 12% (inside 10%..80%); the 0.2 step is at 85% and valid: the line goes between 0.15 (66%) and 0.2 (85%)
            's-1' => self::ladder('s-1', [[0.025, 0.12, true], [0.035, 0.16, true], [0.05, 0.22, true], [0.07, 0.31, true], [0.1, 0.44, true], [0.15, 0.66, true], [0.2, 0.85, true]]),
            // (b) the 0.2 step is at 90% but did not come out (invalid): the line is extended from the two largest valid steps (0.1 at 50%, 0.15 at 70%).
            // 0.025 sits at exactly 10%, the edge of the usable range
            's-2' => self::ladder('s-2', [[0.025, 0.10, true], [0.035, 0.14, true], [0.05, 0.20, true], [0.07, 0.29, true], [0.1, 0.50, true], [0.15, 0.70, true], [0.2, 0.90, false]]),
            // (c) the 0.07 step did not come out while the DB was at 40%: the scenario stops below 80%. 0.025 is at 9% (valid, but not usable)
            's-3' => self::ladder('s-3', [[0.025, 0.09, true], [0.035, 0.14, true], [0.05, 0.20, true], [0.07, 0.40, false]]),
            // (d) 0.025 valid, 0.035 invalid: a single valid step
            's-4' => self::ladder('s-4', [[0.025, 0.15, true], [0.035, 0.30, false]]),
            // (e) the 0.035 step comes out at exactly +5% of the decided rps (valid); the scenario crosses 80% between 0.2 (78%) and 0.3 (97%)
            's-5' => self::ladder('s-5', [[0.025, 0.11, true], [0.035, 0.15, true], [0.05, 0.21, true], [0.07, 0.30, true], [0.1, 0.43, true], [0.15, 0.64, true], [0.2, 0.78, true], [0.3, 0.97, true]], ['0.035' => 1.05]),
        ];
    }

    public function test_the_five_shapes_on_15_step_ladders_are_scored_as_the_score_defines_them(): void
    {
        ['score' => $score, 'stdout' => $stdout] = $this->score(['s-1', 's-2', 's-3', 's-4', 's-5'], self::fiveShapes());
        $by = array_column($score['scenarios'], null, 'id');

        // every scenario keeps its 15 steps, in order, and the two new low steps are in the table
        $this->assertSame(['s-1', 's-2', 's-3', 's-4', 's-5'], array_column($score['scenarios'], 'id'));
        foreach ($by as $id => $s) {
            $this->assertCount(15, $s['stages'], $id);
            // equal as numbers: 1.0 and 5.0 come back from JSON as 1 and 5
            $this->assertEquals(Ladder::RUNGS_S, array_column($s['stages'], 'rps'), $id);
        }
        $this->assertSame(5, $score['counts']['scenarios']);

        // the steps measured: s-1 7, s-2 7, s-3 4, s-4 2, s-5 8 (the rest are not measured: observed null)
        $measured = static fn (array $s): int => count(array_filter($s['stages'], static fn (array $st): bool => $st['observed'] !== null));
        $this->assertSame([7, 7, 4, 2, 8], array_map($measured, array_values($by)));
        $this->assertSame(28, $score['counts']['stages_measured']);

        // 0.025 and 0.035 are stages like any other: usable when valid and 10%..90% (10% exactly is in, 9% is out, an invalid step is out)
        $usable = static fn (array $s, int $i): bool => $s['stages'][$i]['usable'];
        $this->assertTrue($usable($by['s-1'], 0) && $usable($by['s-1'], 1));
        $this->assertTrue($usable($by['s-2'], 0), '10% exactly is usable');
        $this->assertFalse($usable($by['s-3'], 0), '9% is not usable');
        $this->assertTrue($by['s-3']['stages'][0]['valid'], '9% is still a valid stage (it counts for item 3)');
        $this->assertTrue($usable($by['s-3'], 1));
        $this->assertTrue($usable($by['s-4'], 0));
        $this->assertFalse($usable($by['s-4'], 1), 'an invalid step is not usable');
        $this->assertTrue($usable($by['s-5'], 1), 'the 0.035 step at the edge of the 5% check is valid and usable');
        $this->assertFalse($usable($by['s-5'], 7), '97% is above 90%');

        // usable: s-1 7, s-2 6 (0.2 is invalid), s-3 2, s-4 1, s-5 7 (0.3 is above 90%) = 23; valid: 7 + 6 + 3 + 1 + 8 = 25
        $this->assertSame(23, $score['counts']['usable_stages']);
        $this->assertSame(25, $score['counts']['valid_stages']);
        $this->assertSame(25, $score['items']['3']['valid_stages'], 'item 3 counts every valid stage');

        // (a) interpolated between 0.15 (66%) and 0.2 (85%)
        $a = $by['s-1']['limit']['measured'];
        $this->assertSame(['measured', 'interpolated'], [$a['status'], $a['method']]);
        $this->assertEqualsWithDelta(0.15 + (0.80 - 0.66) / (0.85 - 0.66) * 0.05, $a['rps'], 1e-12);
        $this->assertSame([0.15, 0.2], array_column($a['basis'], 'rps'));
        // (b) extended from 0.1 (50%) and 0.15 (70%): 0.15 + 0.10 / 4 = 0.175
        $b = $by['s-2']['limit']['measured'];
        $this->assertSame(['measured', 'extended'], [$b['status'], $b['method']]);
        $this->assertEqualsWithDelta(0.175, $b['rps'], 1e-12);
        $this->assertSame([0.1, 0.15], array_column($b['basis'], 'rps'));
        // (c) stopped below 80% on an invalid step
        $c = $by['s-3']['limit']['measured'];
        $this->assertSame(['not_measured', 'app_bound_below_threshold'], [$c['status'], $c['reason_code']]);
        // (d) one valid step
        $d = $by['s-4']['limit']['measured'];
        $this->assertSame(['not_measured', 'fewer_than_two_valid'], [$d['status'], $d['reason_code']]);
        // (e) interpolated between 0.2 (78%) and 0.3 (97%)
        $e = $by['s-5']['limit']['measured'];
        $this->assertSame(['measured', 'interpolated'], [$e['status'], $e['method']]);
        $this->assertEqualsWithDelta(0.2 + (0.80 - 0.78) / (0.97 - 0.78) * 0.1, $e['rps'], 1e-12);

        // three limits measured is under the four the score asks for: indeterminate, never a pass, and the reason is the count of limits
        $this->assertSame(3, $score['counts']['scenarios_with_limit']);
        $this->assertSame('indeterminate', $score['verdict']);
        $this->assertSame(['scenarios whose limit rps was measured 3 < 4'], $score['indeterminate']['reasons']);
        $this->assertStringContainsString('verdict indeterminate', $stdout);
    }

    public function test_with_a_fourth_measured_limit_the_same_15_step_inputs_are_no_longer_indeterminate(): void
    {
        $ladders = self::fiveShapes();
        // s-3 now carries on and crosses 80% (valid at 0.2: 83%), so four scenarios have a measured limit
        $ladders['s-3'] = self::ladder('s-3', [[0.025, 0.09, true], [0.035, 0.14, true], [0.05, 0.20, true], [0.07, 0.29, true], [0.1, 0.45, true], [0.15, 0.65, true], [0.2, 0.83, true]]);
        ['score' => $score] = $this->score(['s-1', 's-2', 's-3', 's-4', 's-5'], $ladders);

        $this->assertSame(4, $score['counts']['scenarios_with_limit']);
        $this->assertSame([], $score['indeterminate']['reasons']);
        $this->assertNotSame('indeterminate', $score['verdict']);
        // usable 23 - 2 + 6 = 27 (s-3 now has six usable stages: 0.035 to 0.15 and 0.2; 0.025 at 9% is not)
        $this->assertSame(27, $score['counts']['usable_stages']);
        $this->assertSame(4, $score['items']['4']['scenarios_with_limit']);
        // the predicted limit point is 0.2 for all. s-1 measured 0.18684 (0.2 is 7.0% above: within 10% and 20%), s-2 measured 0.175 (14.3% above:
        // within 20% only), s-5 measured 0.2105 (5.0% below: within both); s-3 crosses between 0.15 (65%) and 0.2 (83%): 0.1917 (4.3%: within both)
        $this->assertSame(4, $score['items']['4']['within_minimum']);
        $this->assertSame(3, $score['items']['4']['within_target']);
        $this->assertSame(['s-1', 's-2', 's-3', 's-5'], array_values(array_map(
            static fn (array $s): string => $s['id'],
            array_filter($score['scenarios'], static fn (array $s): bool => $s['limit']['measured']['status'] === 'measured'),
        )));
    }

    public function test_a_scenario_with_13_steps_and_one_with_15_in_one_file_are_each_taken_as_the_file_has_them(): void
    {
        $p = self::prediction(['s-1', 't-1']);
        $p['scenarios'][1]['stages'] = array_slice($p['scenarios'][1]['stages'], 2); // 13 steps from 0.05
        file_put_contents($this->dir . '/prediction.json', json_encode($p, JSON_THROW_ON_ERROR));
        $s = self::ladder('s-1', [[0.025, 0.12, true], [0.035, 0.16, true], [0.05, 0.22, true]]);
        $t = self::ladder('t-1', [[0.05, 0.22, true], [0.07, 0.31, true]]);
        file_put_contents($this->dir . '/s.json', json_encode($s, JSON_THROW_ON_ERROR));
        file_put_contents($this->dir . '/t.json', json_encode($t, JSON_THROW_ON_ERROR));
        [$code, , $stderr] = $this->runCommand('--prediction=' . $this->dir . '/prediction.json', '--ladder=' . $this->dir . '/s.json', '--ladder=' . $this->dir . '/t.json', '--out=' . $this->dir . '/out');
        $this->assertSame(0, $code, $stderr);
        $score = json_decode((string) file_get_contents($this->dir . '/out/score.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([15, 13], array_map(static fn (array $x): int => count($x['stages']), $score['scenarios']));

        // a measured step that is not a step of the file's ladder for that scenario is still refused: 0.025 is not a step of t-1's 13
        $t = self::ladder('t-1', [[0.025, 0.12, true]]);
        file_put_contents($this->dir . '/t.json', json_encode($t, JSON_THROW_ON_ERROR));
        [$code, , $stderr] = $this->runCommand('--prediction=' . $this->dir . '/prediction.json', '--ladder=' . $this->dir . '/t.json', '--out=' . $this->dir . '/out');
        $this->assertSame(2, $code);
        $this->assertStringContainsString('not a stage of the predicted ladder', $stderr);
    }

    public function test_the_score_of_the_first_backtest_is_byte_for_byte_what_was_committed(): void
    {
        $root = realpath(__DIR__ . '/../../../../..');
        $this->assertNotFalse($root);
        $data = $root . '/docs/research/lab-data';
        $args = ['--prediction=' . $root . '/poc/lab/predictions/ladder-prediction.json', '--out=' . $this->dir . '/out'];
        foreach (['t-1-30000', 't-2-100000', 't-3-100000', 't-4-10000', 't-5-100000'] as $d) {
            $args[] = '--ladder=' . $data . '/ladder/' . $d . '/ladder.json';
        }
        [$code, , $stderr] = $this->runCommand(...$args);
        $this->assertSame(0, $code, $stderr);
        foreach (['score.json', 'score.md'] as $f) {
            $this->assertSame(
                (string) file_get_contents($data . '/score/' . $f),
                (string) file_get_contents($this->dir . '/out/' . $f),
                $f . ' of t-1 .. t-5 differs from the committed one',
            );
        }
    }
}
