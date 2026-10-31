<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance;

use PeakSim\Tests\Support\MiniAppAnalyzer;
use PeakSim\Tests\Support\MiniAppRealAnalyzer;
use PeakSim\Tests\Support\MiniAppWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * The JSON of a report is a function of the app and the export only (design 3.7, I6):
 * the same bytes twice in one process, twice in separate processes, from a copy of the app somewhere else on
 * disk, and under another TZ / LC_ALL. Nothing of the machine (paths, host, time) may reach the output.
 */
final class DeterminismTest extends TestCase
{
    /** @var list<string> */
    private array $copies = [];

    protected function tearDown(): void
    {
        foreach ($this->copies as $copy) {
            MiniAppWorkspace::remove($copy);
        }
    }

    protected function analyzer(): MiniAppAnalyzer
    {
        return new MiniAppRealAnalyzer();
    }

    private function inProcess(?string $appRoot = null): string
    {
        return $this->analyzer()->json($appRoot ?? MiniAppWorkspace::fixtureApp(), MiniAppWorkspace::fixtureExport());
    }

    public function testTheSameProcessGivesTheSameBytesTwice(): void
    {
        $first = $this->inProcess();
        $second = $this->inProcess();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $second);
    }

    public function testSeparateProcessesGiveTheSameBytesAsEachOtherAndAsThisProcess(): void
    {
        $inProcess = $this->inProcess();

        $this->assertSame($inProcess, $this->inChildProcess(MiniAppWorkspace::fixtureApp()));
        $this->assertSame($inProcess, $this->inChildProcess(MiniAppWorkspace::fixtureApp()));
    }

    public function testACopyOfTheAppSomewhereElseGivesTheSameBytes(): void
    {
        $copy = $this->copies[] = MiniAppWorkspace::copyApp(MiniAppWorkspace::fixtureApp(), 'det');

        $original = $this->inProcess();
        $this->assertSame($original, $this->inProcess($copy), 'an absolute path of the app root leaked into the output');
        // A path, not a prefix: the route /hidden/appends legitimately contains "/app".
        foreach ([$copy, MiniAppWorkspace::projectRoot(), sys_get_temp_dir(), '/home/'] as $machinePath) {
            $this->assertStringNotContainsString(rtrim($machinePath, '/') . '/', $original, 'a path of this machine reached the output');
        }
    }

    public function testAnotherTimezoneAndLocaleGiveTheSameBytes(): void
    {
        $base = $this->inChildProcess(MiniAppWorkspace::fixtureApp(), ['TZ' => 'UTC', 'LC_ALL' => 'C']);

        $this->assertSame($base, $this->inChildProcess(MiniAppWorkspace::fixtureApp(), ['TZ' => 'Pacific/Auckland', 'LC_ALL' => 'C.UTF-8']));
        $this->assertSame($base, $this->inChildProcess(MiniAppWorkspace::fixtureApp(), ['TZ' => 'America/Los_Angeles', 'LANG' => 'C', 'LC_COLLATE' => 'C', 'LC_ALL' => '']));
    }

    /** @param array<string, string> $env */
    private function inChildProcess(string $appRoot, array $env = []): string
    {
        // A child inherits what the parent was started with (a prepended stand-in of the tool, when the harness is proved without the tool).
        $prepend = ini_get('auto_prepend_file');
        $cmd = [PHP_BINARY, '-d', 'display_errors=stderr', ...($prepend ? ['-d', 'auto_prepend_file=' . $prepend] : []),
            __DIR__ . '/Support/analyze.php', $appRoot, MiniAppWorkspace::fixtureExport()];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
        $this->assertIsResource($process, 'cannot start a child PHP process');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $this->assertSame(0, $code, "the child process failed:\n" . substr((string) $stderr, 0, 2000));

        return (string) $stdout;
    }
}
