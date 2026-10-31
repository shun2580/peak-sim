<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Input;

use PeakSim\Input\ArtisanFailedException;
use PeakSim\Input\ProcessResult;
use PeakSim\Input\ProcessRunner;
use PeakSim\Input\ProcOpenRunner;
use PHPUnit\Framework\TestCase;

final class ProcOpenRunnerTest extends TestCase
{
    public function testItIsAProcessRunner(): void
    {
        self::assertInstanceOf(ProcessRunner::class, new ProcOpenRunner());
    }

    public function testStdoutStderrAndExitCodeAreReadSeparately(): void
    {
        $result = $this->php('fwrite(STDOUT, "out-text"); fwrite(STDERR, "err-text"); exit(3);');

        self::assertInstanceOf(ProcessResult::class, $result);
        self::assertSame(3, $result->exitCode);
        self::assertSame('out-text', $result->stdout);
        self::assertSame('err-text', $result->stderr);
    }

    public function testExitCodeZero(): void
    {
        $result = $this->php('echo "ok";');

        self::assertSame(0, $result->exitCode);
        self::assertSame('ok', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testBigStderrBeforeStdoutDoesNotBlockThePipes(): void
    {
        $started = microtime(true);

        $result = $this->php('fwrite(STDERR, str_repeat("E", 300 * 1024)); fwrite(STDOUT, "done");', 20);

        self::assertSame(0, $result->exitCode);
        self::assertSame('done', $result->stdout);
        self::assertSame(300 * 1024, strlen($result->stderr));
        self::assertLessThan(15.0, microtime(true) - $started);
    }

    public function testBigStdoutWhileStderrIsAlsoWrittenDoesNotBlockThePipes(): void
    {
        $result = $this->php(
            'for ($i = 0; $i < 40; $i++) { fwrite(STDOUT, str_repeat("o", 16384)); fwrite(STDERR, str_repeat("e", 16384)); }',
            20,
        );

        self::assertSame(0, $result->exitCode);
        self::assertSame(40 * 16384, strlen($result->stdout));
        self::assertSame(40 * 16384, strlen($result->stderr));
    }

    public function testTheCommandRunsInTheGivenDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/peak-sim-runner-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            $result = $this->php('echo getcwd();', 20, $dir);

            self::assertSame(realpath($dir), $result->stdout);
        } finally {
            rmdir($dir);
        }
    }

    public function testStdinIsClosedSoACommandThatReadsItSeesEndOfFile(): void
    {
        $result = $this->php('echo strlen(stream_get_contents(STDIN));', 20);

        self::assertSame('0', $result->stdout);
    }

    public function testEnvironmentOfThisProcessIsPassedOn(): void
    {
        putenv('PEAK_RUNNER_TEST=visible');
        try {
            $result = $this->php('echo getenv("PEAK_RUNNER_TEST");');
        } finally {
            putenv('PEAK_RUNNER_TEST');
        }

        self::assertSame('visible', $result->stdout);
    }

    public function testACommandThatTakesTooLongIsStoppedWithAnArtisanFailedException(): void
    {
        $started = microtime(true);

        try {
            $this->php('sleep(30);', 1);
            self::fail('a timeout exception was expected');
        } catch (ArtisanFailedException $e) {
            self::assertStringContainsStringIgnoringCase('time', $e->getMessage());
        }

        self::assertLessThan(10.0, microtime(true) - $started);
    }

    public function testACommandThatDoesNotExistGivesNonZeroExitNotAHang(): void
    {
        $result = (new ProcOpenRunner())->run('peak-sim-no-such-command-xyz', sys_get_temp_dir(), 20);

        self::assertNotSame(0, $result->exitCode);
    }

    public function testAMissingWorkingDirectoryIsAnArtisanFailedException(): void
    {
        $this->expectException(ArtisanFailedException::class);

        (new ProcOpenRunner())->run('echo hi', sys_get_temp_dir() . '/peak-sim-no-such-dir-' . bin2hex(random_bytes(4)), 5);
    }

    private function php(string $code, int $timeout = 20, ?string $cwd = null): ProcessResult
    {
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);

        return (new ProcOpenRunner())->run($command, $cwd ?? sys_get_temp_dir(), $timeout);
    }
}
