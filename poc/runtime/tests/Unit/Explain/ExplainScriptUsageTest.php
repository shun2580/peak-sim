<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `bin/explain-at-scale` answers a wrong usage before it touches Docker (exit 2 and the usage text), passes the app key by
 * name, never kills a process, and keeps the two doors apart in its code.
 */
final class ExplainScriptUsageTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/peak-explain-usage-' . bin2hex(random_bytes(4));
        mkdir(self::$dir . '/app/vendor', 0775, true);
        touch(self::$dir . '/app/vendor/autoload.php');
        file_put_contents(self::$dir . '/capture.json', '{}');
        file_put_contents(self::$dir . '/tasks.json', '[]');
        mkdir(self::$dir . '/out/taken', 0775, true);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['/app/vendor/autoload.php', '/capture.json', '/tasks.json'] as $f) {
            @unlink(self::$dir . $f);
        }
        @rmdir(self::$dir . '/app/vendor');
        @rmdir(self::$dir . '/app');
        @rmdir(self::$dir . '/out/taken');
        @rmdir(self::$dir . '/out');
        @rmdir(self::$dir);
    }

    private static function script(): string
    {
        return dirname(__DIR__, 3) . '/bin/explain-at-scale';
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function invoke(array $args): array
    {
        $process = proc_open(['bash', self::script(), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir()]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /** @return list<string> */
    private static function good(): array
    {
        return ['--capture=' . self::$dir . '/capture.json', '--tasks=' . self::$dir . '/tasks.json', '--app=' . self::$dir . '/app', '--scale=100000', '--out=' . self::$dir . '/out'];
    }

    /**
     * @param list<string> $args
     *
     * @return list<string>
     */
    private static function without(string $name, array $args): array
    {
        return array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, $name . '=')));
    }

    /** @return array<string, array{0: string, 1: string}> what to spoil in the good arguments, and the words of the answer */
    public static function wrongUsage(): array
    {
        return [
            'no arguments' => ['none', '--capture is required'],
            'no capture' => ['-capture', '--capture is required'],
            'no tasks' => ['-tasks', '--tasks is required'],
            'no app' => ['-app', '--app is required'],
            'no scale' => ['-scale', '--scale is required'],
            'no out' => ['-out', '--out is required'],
            'a relative capture' => ['--capture=capture.json', 'absolute path'],
            'a scale that is not a number' => ['--scale=many', '--scale must be a positive integer'],
            'a small scale as big as the target' => ['--small-scale=100000', '--small-scale must be smaller'],
            'a bad label' => ['--label=a b', '--label'],
            'a bad profile' => ['--profile=../x', '--profile'],
            'a bad data mode' => ['--data=dump', '--data takes generator or seeder'],
            'an unknown argument' => ['--force', 'unknown argument'],
            'an app without vendor' => ['--app=@out', 'no vendor/autoload.php'],
            'a capture that is not a file' => ['--capture=@none.json', '--capture is not a file'],
        ];
    }

    #[DataProvider('wrongUsage')]
    public function testItAnswersAWrongUsageWithExitTwoAndTheUsageText(string $spoil, string $why): void
    {
        $args = self::good();
        if ($spoil === 'none') {
            $args = [];
        } elseif (str_starts_with($spoil, '-') && !str_starts_with($spoil, '--')) {
            $args = self::without('-' . $spoil, $args);
        } else {
            $name = strstr($spoil, '=', true);
            $value = str_replace('@', self::$dir . '/', substr((string) strstr($spoil, '='), 1));
            $args = [...self::without((string) $name, $args), $name . '=' . $value];
        }

        [$code, , $err] = self::invoke($args);

        $this->assertSame(2, $code, $err);
        $this->assertStringContainsString($why, $err);
        $this->assertStringContainsString('usage: bin/explain-at-scale', $err);
    }

    public function testItNeverWritesOverARecord(): void
    {
        mkdir(self::$dir . '/out/taken/explain', 0775, true);
        [$code, , $err] = self::invoke([...self::good(), '--out=' . self::$dir . '/out/taken']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('already exists', $err);
        @rmdir(self::$dir . '/out/taken/explain');
    }

    public function testHelpExitsZeroAndNamesTheExitCodes(): void
    {
        [$code, $out] = self::invoke(['--help']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('10  a statement that was not one EXPLAIN FORMAT=JSON', $out);
    }

    public function testTheScriptHasNoKillAndPassesTheAppKeyByName(): void
    {
        $text = (string) file_get_contents(self::script());

        $this->assertDoesNotMatchRegularExpression('/\b(kill|killall|pkill)\b/', $text);
        $this->assertStringContainsString('-e PEAK_MEASURE_APP_KEY', $text);
        $this->assertDoesNotMatchRegularExpression('/PEAK_MEASURE_APP_KEY=/', preg_replace('/export PEAK_MEASURE_APP_KEY="\$\(peak_app_key_for_run "\$run_id"\)"/', '', $text) ?? '');
    }

    public function testTheScriptSendsNoStatementOfTheCaptureItself(): void
    {
        // Every statement of the capture is sent by the PHP step through ExplainSender; the shell only copies statistics and runs steps.
        $text = (string) file_get_contents(self::script());

        $code = preg_replace(["/cat <<'TXT'.*?\nTXT\n/s", '/^\s*#.*$/m'], '', $text) ?? '';
        $this->assertStringNotContainsString('EXPLAIN', $code);
        $this->assertSame(1, preg_match_all('/mysql_root -e/', $text), 'the only statements the shell sends are the statistics copies (one call, three SELECTs)');
    }
}
