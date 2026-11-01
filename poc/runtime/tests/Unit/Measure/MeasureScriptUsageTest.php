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
        yield 'bad scales' => [['--app=/x', '--static=/y', '--scales=1,2']];
        yield 'timeout of zero' => [['--app=/x', '--static=/y', '--request-timeout=0']];
        yield 'timeout over a minute' => [['--app=/x', '--static=/y', '--request-timeout=61']];
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

    public function test_help_prints_the_usage_and_the_exit_codes_and_exits_0(): void
    {
        [$code, $out] = self::invoke(['--help']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('usage: bin/measure', $out);
        foreach (['10', '11', '12', '13', '14'] as $exit) {
            $this->assertStringContainsString($exit, $out);
        }
        $this->assertStringContainsString('--request-timeout', $out);
    }
}
