<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The host-side entry point answers a wrong usage before it touches Docker: exit code 2 and the usage text. */
final class MeasureScriptUsageTest extends TestCase
{
    private static function script(): string
    {
        return dirname(__DIR__, 3) . '/bin/measure';
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function invoke(array $args): array
    {
        $process = proc_open(['bash', self::script(), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), 'PEAK_OUT_DIR' => sys_get_temp_dir() . '/peak-usage-test',
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
        yield 'no static analysis' => [['--app=/x']];
        yield 'no app' => [['--static=/y']];
        yield 'relative app' => [['--app=x', '--static=/y']];
        yield 'bad data kind' => [['--app=/x', '--static=/y', '--data=dump']];
        yield 'scales descending' => [['--app=/x', '--static=/y', '--scales=2,1']];
        yield 'scales repeated' => [['--app=/x', '--static=/y', '--scales=5,5']];
        yield 'scales not numbers' => [['--app=/x', '--static=/y', '--scales=a']];
        yield 'scales empty' => [['--app=/x', '--static=/y', '--scales=']];
        yield 'timeout of zero' => [['--app=/x', '--static=/y', '--request-timeout=0']];
        yield 'timeout over ten minutes' => [['--app=/x', '--static=/y', '--request-timeout=601']];
        yield 'timeout of four digits' => [['--app=/x', '--static=/y', '--request-timeout=1000']];
        yield 'warm maybe' => [['--app=/x', '--static=/y', '--warm=maybe']];
        yield 'profile with a path' => [['--app=/x', '--static=/y', '--profile=../x']];
        yield 'profile with a dot' => [['--app=/x', '--static=/y', '--profile=a.php']];
        yield 'empty profile' => [['--app=/x', '--static=/y', '--profile=']];
        yield 'analyze after build maybe' => [['--app=/x', '--static=/y', '--analyze-after-build=maybe']];
        yield 'gate bypass maybe' => [['--app=/x', '--static=/y', '--gate-bypass=maybe']];
        yield 'timeout not a number' => [['--app=/x', '--static=/y', '--request-timeout=soon']];
    }

    /** @param list<string> $args */
    #[DataProvider('bad')]
    public function test_a_wrong_usage_exits_with_2_and_says_how_to_use_it(array $args): void
    {
        [$code, , $err] = self::invoke($args);

        $this->assertSame(2, $code, $err);
        $this->assertStringContainsString('usage: bin/measure', $err);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function accepted(): iterable
    {
        yield 'one scale' => [['--scales=100000']];
        yield 'two scales' => [['--scales=100,1000']];
        yield 'ten minutes' => [['--request-timeout=600']];
        yield 'three digits' => [['--request-timeout=100']];
        yield 'warm off' => [['--warm=off']];
        yield 'all three' => [['--scales=100000', '--request-timeout=600', '--warm=off']];
    }

    /**
     * The options pass the checks of the script itself: it stops only at the next one (the app is not a directory),
     * long before it touches Docker.
     *
     * @param list<string> $args
     */
    #[DataProvider('accepted')]
    public function test_a_single_scale_a_long_timeout_and_no_warm_request_are_accepted(array $args): void
    {
        [$code, , $err] = self::invoke(['--app=/nonexistent-app-dir', '--static=/y', ...$args]);

        $this->assertSame(2, $code, $err);
        $this->assertStringContainsString('--app is not a directory', $err);
    }

    public function test_help_prints_the_usage_and_the_exit_codes_and_exits_0(): void
    {
        [$code, $out] = self::invoke(['--help']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('usage: bin/measure', $out);
        foreach (['10', '11', '12', '13', '14'] as $exit) {
            $this->assertStringContainsString($exit, $out);
        }
        $this->assertStringContainsString('--request-timeout', $out);
        $this->assertStringContainsString('--gate-bypass', $out);
        $this->assertStringContainsString('--warm', $out);
    }

    public function test_help_names_the_variable_that_runs_the_stack_in_another_image(): void
    {
        [$code, $out] = self::invoke(['--help']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('PEAK_PHP_IMAGE', $out);
        $this->assertStringContainsString('build-image', $out, 'and where such an image comes from');
    }
}
