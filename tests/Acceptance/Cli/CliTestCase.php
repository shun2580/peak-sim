<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Cli;

use PeakSim\Input\Contracts\ProcessResult;
use PeakSim\Input\ProcOpenRunner;
use PeakSim\Tests\Support\MiniAppWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real `bin/peak-sim` in a process of its own, so that exit codes and the two output streams are the
 * ones a user sees. The stub artisan (tests/Support/artisan-stub) stands in for a Laravel app: no Laravel, no
 * database, so this works on PHP 8.2 as well and nothing is skipped.
 */
abstract class CliTestCase extends TestCase
{
    private const STUB_ENV = 'PEAK_STUB_EXPORT_DIR';

    private string|false $previousExportDir = false;

    private string|false $previousMode = false;

    protected function setUp(): void
    {
        $this->previousExportDir = getenv(self::STUB_ENV);
        $this->previousMode = getenv('PEAK_STUB_MODE');
        putenv(self::STUB_ENV . '=' . MiniAppWorkspace::fixtureExport());
        putenv('PEAK_STUB_MODE=ok');
    }

    protected function tearDown(): void
    {
        putenv($this->previousExportDir === false ? self::STUB_ENV : self::STUB_ENV . '=' . $this->previousExportDir);
        putenv($this->previousMode === false ? 'PEAK_STUB_MODE' : 'PEAK_STUB_MODE=' . $this->previousMode);
    }

    /** @param list<string> $args already-separate arguments; they are escaped here */
    protected function peakSim(array $args): ProcessResult
    {
        $binary = MiniAppWorkspace::projectRoot() . '/bin/peak-sim';
        $this->assertFileExists($binary, 'bin/peak-sim is not there yet');
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary)
            . ($args === [] ? '' : ' ' . implode(' ', array_map('escapeshellarg', $args)));

        return (new ProcOpenRunner())->run($command, MiniAppWorkspace::projectRoot(), 120);
    }

    /** `analyze <fixture app>` plus the given options. */
    protected function analyze(string ...$options): ProcessResult
    {
        return $this->peakSim(['analyze', MiniAppWorkspace::fixtureApp(), ...$options]);
    }

    protected function exportOption(): string
    {
        return '--export-dir=' . MiniAppWorkspace::fixtureExport();
    }

    protected function stubOption(): string
    {
        $stub = MiniAppWorkspace::projectRoot() . '/tests/Support/artisan-stub/artisan';
        $this->assertFileExists($stub, 'the stub artisan is not there');

        return '--artisan-command=' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stub);
    }

    /** @return array<string, mixed> */
    protected function decodeJson(string $stdout): array
    {
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
