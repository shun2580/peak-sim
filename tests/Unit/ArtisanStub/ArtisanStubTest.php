<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\ArtisanStub;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * スタブ artisan(tests/Support/artisan-stub/artisan)の自己試験。
 * 各モードで、標準出力・標準エラー・終了コードが仕様どおりかを確かめる。
 */
final class ArtisanStubTest extends TestCase
{
    private const STUB = __DIR__ . '/../../Support/artisan-stub/artisan';
    private const EXPORT_DIR = __DIR__ . '/../../../fixtures/artisan-export';

    public function testRouteListIsPrintedVerbatimInOkMode(): void
    {
        $r = $this->stub(['route:list', '--json'], 'ok');

        self::assertSame(0, $r['exit']);
        self::assertSame(file_get_contents(self::EXPORT_DIR . '/route-list.json'), $r['stdout']);
        self::assertSame('', $r['stderr']);
        self::assertCount(26, json_decode($r['stdout'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testModelShowAcceptsFqcnWithAndWithoutLeadingBackslash(): void
    {
        $expected = file_get_contents(self::EXPORT_DIR . '/model-show/Order.json');

        $plain = $this->stub(['model:show', 'App\\Models\\Order', '--json'], 'ok');
        $leading = $this->stub(['model:show', '\\App\\Models\\Order', '--json'], 'ok');

        self::assertSame(0, $plain['exit']);
        self::assertSame($expected, $plain['stdout']);
        self::assertSame(0, $leading['exit']);
        self::assertSame($expected, $leading['stdout']);
    }

    public function testModelShowUnknownClassFailsWithStderrOnly(): void
    {
        $r = $this->stub(['model:show', 'App\\Models\\NoSuchModel', '--json'], 'ok');

        self::assertSame(1, $r['exit']);
        self::assertSame('', $r['stdout']);
        self::assertNotSame('', $r['stderr']);
    }

    public function testOutputDoesNotContainPathsOfTheHost(): void
    {
        $r = $this->stub(['route:list', '--json'], 'ok');

        self::assertStringNotContainsString(realpath(self::EXPORT_DIR), $r['stdout']);
        self::assertStringNotContainsString(realpath(self::EXPORT_DIR), $r['stderr']);
    }

    public function testWarnStdoutPutsNonJsonLineBeforeTheJson(): void
    {
        $r = $this->stub(['route:list', '--json'], 'warn_stdout');

        self::assertSame(0, $r['exit']);
        self::assertNull(json_decode($r['stdout'], true));
        self::assertStringContainsString('Deprecated', strtok($r['stdout'], "\n"));
        $rest = substr($r['stdout'], strpos($r['stdout'], "\n") + 1);
        self::assertSame(file_get_contents(self::EXPORT_DIR . '/route-list.json'), $rest);
    }

    public function testExit1ModePrintsNothingOnStdoutAndOneLineOnStderr(): void
    {
        $r = $this->stub(['route:list', '--json'], 'exit1');

        self::assertSame(1, $r['exit']);
        self::assertSame('', $r['stdout']);
        self::assertSame(1, substr_count(rtrim($r['stderr'], "\n"), "\n") + 1);
    }

    public function testBigStderrModeWritesOver200KbAndStillPrintsJson(): void
    {
        $r = $this->stub(['route:list', '--json'], 'big_stderr');

        self::assertSame(0, $r['exit']);
        self::assertGreaterThanOrEqual(200 * 1024, strlen($r['stderr']));
        self::assertSame(file_get_contents(self::EXPORT_DIR . '/route-list.json'), $r['stdout']);
    }

    public function testNonJsonModePrintsNotJsonWithExit0(): void
    {
        $r = $this->stub(['route:list', '--json'], 'nonjson');

        self::assertSame(0, $r['exit']);
        self::assertSame("not json\n", $r['stdout']);
    }

    public function testMissingExportDirIsUsageError(): void
    {
        $r = $this->runRaw(['route:list', '--json'], ['PATH' => (string) getenv('PATH')]);

        self::assertSame(2, $r['exit']);
        self::assertSame('', $r['stdout']);
    }

    public function testUnknownModeIsUsageError(): void
    {
        $r = $this->stub(['route:list', '--json'], 'no_such_mode');

        self::assertSame(2, $r['exit']);
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    private function stub(array $args, string $mode): array
    {
        return $this->runRaw($args, [
            'PATH' => (string) getenv('PATH'),
            'PEAK_STUB_EXPORT_DIR' => realpath(self::EXPORT_DIR),
            'PEAK_STUB_MODE' => $mode,
        ]);
    }

    /**
     * 標準出力と標準エラーを、どちらも詰まらないように同時に読む。
     * 片方ずつ読むと big_stderr で固まるため、stream_select で両方を読む。
     *
     * @return array{exit:int, stdout:string, stderr:string}
     */
    private function runRaw(array $args, array $env): array
    {
        $cmd = array_merge([PHP_BINARY, self::STUB], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('proc_open failed');
        }

        $streams = [1 => $pipes[1], 2 => $pipes[2]];
        $buffers = [1 => '', 2 => ''];
        while ($streams !== []) {
            $read = $streams;
            $write = [];
            $except = [];
            if (stream_select($read, $write, $except, 10) === false || $read === []) {
                proc_terminate($proc);
                proc_close($proc);
                throw new RuntimeException('stub did not respond within 10 seconds');
            }
            foreach ($read as $key => $stream) {
                $buffers[$key] .= (string) fread($stream, 8192);
                if (feof($stream)) {
                    fclose($stream);
                    unset($streams[$key]);
                }
            }
        }

        $exit = proc_close($proc);

        return ['exit' => $exit, 'stdout' => $buffers[1], 'stderr' => $buffers[2]];
    }
}
