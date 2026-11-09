<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Capture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `bin/capture` answers a wrong usage before it touches Docker: exit code 2 and the usage text. */
final class CaptureScriptUsageTest extends TestCase
{
    private static function script(): string
    {
        return dirname(__DIR__, 3) . '/bin/capture';
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function invoke(array $args): array
    {
        $process = proc_open(['bash', self::script(), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), 'PEAK_OUT_DIR' => sys_get_temp_dir() . '/peak-capture-usage-test',
        ]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /** @return iterable<string, array{list<string>}> */
    public static function bad(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'unknown option' => [['--app=/x', '--static=/y', '--bogus']];
        yield 'bin/measure option that does not exist here' => [['--app=/x', '--static=/y', '--expected=/z']];
        yield 'warm is not an option here' => [['--app=/x', '--static=/y', '--warm=on']];
        yield 'no static analysis' => [['--app=/x']];
        yield 'no app' => [['--static=/y']];
        yield 'relative app' => [['--app=x', '--static=/y']];
        yield 'bad data kind' => [['--app=/x', '--static=/y', '--data=dump']];
        yield 'one scale' => [['--app=/x', '--static=/y', '--scales=100']];
        yield 'two scales' => [['--app=/x', '--static=/y', '--scales=100,1000']];
        yield 'scales descending' => [['--app=/x', '--static=/y', '--scales=3,2,1']];
        yield 'scales repeated' => [['--app=/x', '--static=/y', '--scales=5,5,6']];
        yield 'scales with a zero' => [['--app=/x', '--static=/y', '--scales=0,1,2']];
        yield 'timeout of zero' => [['--app=/x', '--static=/y', '--request-timeout=0']];
        yield 'timeout over ten minutes' => [['--app=/x', '--static=/y', '--request-timeout=601']];
        yield 'profile with a path' => [['--app=/x', '--static=/y', '--profile=../x']];
        yield 'app name with a slash' => [['--app=/x', '--static=/y', '--app-name=a/b']];
        yield 'gate bypass maybe' => [['--app=/x', '--static=/y', '--gate-bypass=maybe']];
        yield 'n tables with a capital' => [['--app=/x', '--static=/y', '--n-tables=Entities']];
        yield 'n tables with an empty name' => [['--app=/x', '--static=/y', '--n-tables=a,,b']];
        yield 'n tables empty' => [['--app=/x', '--static=/y', '--n-tables=']];
        yield 'n tables with the seeder' => [['--app=/x', '--static=/y', '--data=seeder', '--n-tables=entities']];
    }

    /** @param list<string> $args */
    #[DataProvider('bad')]
    public function test_a_wrong_usage_exits_with_2_and_says_how_to_use_it(array $args): void
    {
        [$code, , $err] = self::invoke($args);

        $this->assertSame(2, $code, $err);
        $this->assertStringContainsString('usage: bin/capture', $err);
    }

    public function test_the_default_scales_pass_the_checks_and_it_stops_at_the_app(): void
    {
        [$code, , $err] = self::invoke(['--app=/nonexistent-app-dir', '--static=/y', '--scales=100,1000,10000', '--app-name=sample.app_1']);

        $this->assertSame(2, $code, $err);
        $this->assertStringContainsString('--app is not a directory', $err);
    }

    public function test_shared_n_tables_pass_the_checks_and_it_stops_at_the_app(): void
    {
        [$code, , $err] = self::invoke(['--app=/nonexistent-app-dir', '--static=/y', '--n-tables=entities,images,references', '--app-name=sample.app_1']);

        $this->assertSame(2, $code, $err);
        $this->assertStringContainsString('--app is not a directory', $err);
    }

    public function test_a_sweep_may_start_at_ten_rows(): void
    {
        // D105: the routes with no empty-system point are captured from N = 10 (10, 100, 1,000, 10,000)
        [$code, , $err] = self::invoke(['--app=/nonexistent-app-dir', '--static=/y', '--scales=10,100,1000,10000', '--app-name=sample.app_1']);

        $this->assertSame(2, $code, $err);
        $this->assertStringContainsString('--app is not a directory', $err);
    }

    public function test_help_prints_the_usage_and_exits_0(): void
    {
        [$code, $out] = self::invoke(['--help']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('usage: bin/capture', $out);
        $this->assertStringContainsString('capture.json', $out);
        $this->assertStringContainsString('--scales', $out);
    }

    public function test_it_is_a_separate_command_that_bin_measure_never_calls(): void
    {
        $measure = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/measure');
        $this->assertStringNotContainsString('capture', $measure);
        $this->assertStringNotContainsString('--tap-dir', $measure);
    }

    public function test_the_driver_is_asked_for_the_tap_directory_and_no_warm_request(): void
    {
        $capture = (string) file_get_contents(self::script());
        $this->assertStringContainsString('--tap-dir=/out/capture-taps', $capture);
        $this->assertStringContainsString('--warm=off', $capture);
        $this->assertStringContainsString('capture-build.php', $capture);
    }
}
