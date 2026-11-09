<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabScore;

use PHPUnit\Framework\TestCase;

/** The command `poc/lab/score/score-ladder.php`, run for real with the PHP that runs the tests: what it writes and what it exits with. */
final class ScoreLadderCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/score-ladder-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,out/}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->dir . '/out')) {
            rmdir($this->dir . '/out');
        }
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

    private function inputFile(array $input): string
    {
        $path = $this->dir . '/input.json';
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));

        return $path;
    }

    private static function scenario(string $id): array
    {
        $stage = static fn (float $rps, float $obs): array => [
            'rps' => $rps,
            'predicted' => ['point' => $obs, 'safe' => $obs + 0.05, 'naive' => $obs + 0.3],
            'measured' => ['valid' => true, 'utilization' => $obs],
        ];

        return [
            'id' => $id,
            'stages' => [$stage(1.0, 0.2), $stage(2.0, 0.4), $stage(3.0, 0.6), $stage(4.0, 0.85)],
            'predicted_limit_rps' => ['point' => 3.8, 'safe' => 3.8, 'naive' => 7.6],
        ];
    }

    public function test_it_writes_the_json_and_the_markdown_and_prints_the_verdict(): void
    {
        $in = $this->inputFile(['threshold' => 0.8, 'scenarios' => [self::scenario('a'), self::scenario('b'), self::scenario('c'), self::scenario('d')]]);
        [$code, $stdout, $stderr] = $this->runCommand('--input=' . $in, '--out=' . $this->dir . '/out');
        $this->assertSame(0, $code, $stderr);
        $this->assertStringContainsString('verdict pass_target', $stdout);
        $score = json_decode((string) file_get_contents($this->dir . '/out/score.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass_target', $score['verdict']);
        $this->assertSame(16, $score['counts']['usable_stages']);
        $md = (string) file_get_contents($this->dir . '/out/score.md');
        $this->assertStringContainsString('## Verdict: PASS at the target', $md);
        $this->assertStringContainsString('## Scenario d', $md);
        $this->assertStringContainsString('Measured limit rps: 3.8 (interpolated', $md);
    }

    public function test_an_indeterminate_result_is_still_exit_0_and_says_so(): void
    {
        $in = $this->inputFile(['threshold' => 0.8, 'scenarios' => [self::scenario('a')]]);
        [$code, $stdout] = $this->runCommand('--input=' . $in, '--out=' . $this->dir . '/out');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('verdict indeterminate', $stdout);
        $this->assertStringContainsString('INDETERMINATE', (string) file_get_contents($this->dir . '/out/score.md'));
    }

    public function test_wrong_usage_and_an_unusable_input_exit_2_and_write_nothing(): void
    {
        $out = $this->dir . '/out';
        $bad = $this->dir . '/bad.json';
        file_put_contents($bad, '{not json');
        $notAnObject = $this->dir . '/list.json';
        file_put_contents($notAnObject, '"text"');
        $cases = [
            [],
            ['--input=' . $this->inputFile(['threshold' => 0.8, 'scenarios' => [self::scenario('a')]])],
            ['--out=' . $out],
            ['--input=' . $this->dir . '/missing.json', '--out=' . $out],
            ['--input=' . $bad, '--out=' . $out],
            ['--input=' . $notAnObject, '--out=' . $out],
            ['--input=' . $this->inputFile(['threshold' => 2, 'scenarios' => [self::scenario('a')]]), '--out=' . $out],
            ['--input=' . $this->inputFile(['threshold' => 0.8, 'scenarios' => []]), '--out=' . $out],
            ['--nonsense'],
        ];
        foreach ($cases as $args) {
            [$code, , $stderr] = $this->runCommand(...$args);
            $this->assertSame(2, $code, implode(' ', $args) . ' => ' . $stderr);
            $this->assertNotSame('', $stderr);
        }
        $this->assertFileDoesNotExist($out . '/score.json');
        $this->assertFileDoesNotExist($out . '/score.md');
    }

    public function test_the_prediction_and_the_ladder_records_are_joined_and_scored(): void
    {
        $row = static fn (float $rps, float $u): array => [
            'rps_total' => $rps, 'rps' => ['GET /a' => $rps], 'utilization' => ['point' => $u, 'upper' => $u + 0.05], 'naive' => $u + 0.3,
            'over_threshold' => ['point' => false, 'upper' => false, 'naive' => false],
        ];
        $limit = ['point' => ['status' => 'found', 'rps' => 3.8], 'safe' => ['status' => 'found', 'rps' => 3.8], 'naive' => ['status' => 'found', 'rps' => 7.6]];
        $prediction = ['kind' => 'lab-ladder-prediction', 'resources' => ['cores' => 2.0, 'threshold' => 0.8], 'scenarios' => [
            ['id' => 't-1', 'stages' => [$row(1.0, 0.2), $row(2.0, 0.4), $row(3.0, 0.6), $row(4.0, 0.85)], 'limit_rps' => $limit],
            ['id' => 't-2', 'stages' => [$row(1.0, 0.2)], 'limit_rps' => $limit],
        ]];
        $rung = static fn (float $rps, float $u): array => ['scenario' => 't-1', 'decided_rps' => $rps, 'rps_valid' => true, 'db_utilization' => $u];
        $ladder = ['scenario' => 't-1', 'rungs' => [$rung(1.0, 0.2), $rung(2.0, 0.4), $rung(3.0, 0.6), $rung(4.0, 0.85)]];
        file_put_contents($this->dir . '/prediction.json', json_encode($prediction, JSON_THROW_ON_ERROR));
        file_put_contents($this->dir . '/ladder.json', json_encode($ladder, JSON_THROW_ON_ERROR));

        [$code, $stdout, $stderr] = $this->runCommand('--prediction=' . $this->dir . '/prediction.json', '--ladder=' . $this->dir . '/ladder.json', '--out=' . $this->dir . '/out');
        $this->assertSame(0, $code, $stderr);
        $this->assertStringContainsString('verdict indeterminate', $stdout);
        $score = json_decode((string) file_get_contents($this->dir . '/out/score.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(4, $score['counts']['usable_stages']);
        $this->assertSame(1, $score['counts']['scenarios_with_limit']);
        $this->assertSame('not_measured', $score['scenarios'][1]['limit']['measured']['status'], 't-2 has no ladder record');

        // mixing the two forms, or a prediction without a ladder, is wrong usage
        [$code] = $this->runCommand('--input=' . $this->dir . '/prediction.json', '--prediction=' . $this->dir . '/prediction.json', '--ladder=' . $this->dir . '/ladder.json', '--out=' . $this->dir . '/out');
        $this->assertSame(2, $code);
        [$code] = $this->runCommand('--prediction=' . $this->dir . '/prediction.json', '--out=' . $this->dir . '/out');
        $this->assertSame(2, $code);
        // a ladder step that is not on the predicted ladder is refused
        $ladder['rungs'][] = $rung(1.5, 0.3);
        file_put_contents($this->dir . '/ladder.json', json_encode($ladder, JSON_THROW_ON_ERROR));
        [$code, , $stderr] = $this->runCommand('--prediction=' . $this->dir . '/prediction.json', '--ladder=' . $this->dir . '/ladder.json', '--out=' . $this->dir . '/out');
        $this->assertSame(2, $code);
        $this->assertStringContainsString('not a stage of the predicted ladder', $stderr);
    }
}
