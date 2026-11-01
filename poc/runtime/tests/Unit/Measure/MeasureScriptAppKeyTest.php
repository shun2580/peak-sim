<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Boot\AppBooter;
use PHPUnit\Framework\TestCase;

/**
 * bin/measure decides one APP_KEY per run from the run id and hands it to every process of the run through the
 * environment of `compose exec`, by name (so it is not on a command line and never printed).
 */
final class MeasureScriptAppKeyTest extends TestCase
{
    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/measure');
    }

    /** Runs the key function of bin/measure (the text between its two marker comments) in bash. */
    private static function keyFor(string $runId): string
    {
        self::assertSame(1, preg_match('/^# >>> app-key\n(.*?)^# <<< app-key$/ms', self::script(), $m), 'bin/measure has no app-key block');
        $process = proc_open(['bash', '-c', $m[1] . "\n" . 'peak_app_key_for_run "$1"', 'bash', $runId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin']);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $err);

        return $out;
    }

    public function test_the_key_made_from_a_run_id_is_one_the_booter_accepts(): void
    {
        $key = self::keyFor('abc123def456');

        self::assertSame($key, AppBooter::appKeyFrom([AppBooter::APP_KEY_ENV => $key]));
    }

    public function test_the_same_run_id_gives_the_same_key_and_another_gives_another(): void
    {
        self::assertSame(self::keyFor('abc123def456'), self::keyFor('abc123def456'));
        self::assertNotSame(self::keyFor('abc123def456'), self::keyFor('abc123def457'));
    }

    public function test_the_key_is_passed_by_name_and_never_printed(): void
    {
        $script = self::script();

        self::assertMatchesRegularExpression('/exec_env\+?=\(.*-e PEAK_MEASURE_APP_KEY(\s|\))/', $script, 'the key must be passed to compose exec by name');
        self::assertSame(0, preg_match('/-e PEAK_MEASURE_APP_KEY=/', $script), 'the key must not be on a command line');
        self::assertSame(0, preg_match('/(echo|printf|cat)\b[^\n]*\$\{?PEAK_MEASURE_APP_KEY/', $script), 'the key must not be printed');
        self::assertStringContainsString('export PEAK_MEASURE_APP_KEY', $script);
    }
}
