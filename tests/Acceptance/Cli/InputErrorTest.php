<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Cli;

use PeakSim\Tests\Support\MiniAppWorkspace;

/**
 * Exit code 3: the way in failed (artisan did not run, or what it printed is not JSON). The message is the one
 * the input layer wrote; the CLI adds where to look. Standard output stays empty.
 */
final class InputErrorTest extends CliTestCase
{
    public function testAnExportDirWithoutTheRouteListExitsWithThree(): void
    {
        $empty = MiniAppWorkspace::make('cli-empty-export');
        try {
            $result = $this->analyze('--export-dir=' . $empty);

            $this->assertSame(3, $result->exitCode, $result->stderr);
            $this->assertSame('', $result->stdout);
            $this->assertStringContainsString('route-list.json', $result->stderr);
        } finally {
            MiniAppWorkspace::remove($empty);
        }
    }

    public function testABrokenRouteListExitsWithThree(): void
    {
        $dir = MiniAppWorkspace::make('cli-broken-export');
        try {
            file_put_contents($dir . '/route-list.json', '{ this is not json');
            $result = $this->analyze('--export-dir=' . $dir);

            $this->assertSame(3, $result->exitCode, $result->stderr);
            $this->assertSame('', $result->stdout);
            $this->assertStringContainsString('route-list.json', $result->stderr);
        } finally {
            MiniAppWorkspace::remove($dir);
        }
    }

    public function testAnArtisanThatPrintsNoJsonExitsWithThreeAndSaysWhatToTry(): void
    {
        putenv('PEAK_STUB_MODE=warn_stdout');

        $result = $this->analyze($this->stubOption());

        $this->assertSame(3, $result->exitCode, $result->stderr);
        $this->assertSame('', $result->stdout);
        $this->assertStringContainsString('display_errors=stderr', $result->stderr, 'the hint to move warnings to stderr');
        $this->assertStringContainsString('not valid JSON', $result->stderr);
    }

    public function testAnArtisanThatFailsExitsWithThree(): void
    {
        putenv('PEAK_STUB_MODE=exit1');

        $result = $this->analyze($this->stubOption());

        $this->assertSame(3, $result->exitCode, $result->stderr);
        $this->assertSame('', $result->stdout);
        $this->assertStringContainsString('exit code 1', $result->stderr);
    }

    public function testWithNeitherOptionItRunsPhpArtisanInTheAppRoot(): void
    {
        // The fixture app has no artisan file of its own in the tool's repository: `php artisan` fails there.
        $root = MiniAppWorkspace::make('cli-no-artisan');
        try {
            $result = $this->peakSim(['analyze', $root]);

            $this->assertSame(3, $result->exitCode, $result->stderr);
            $this->assertSame('', $result->stdout);
            $this->assertStringContainsString('route:list', $result->stderr);
        } finally {
            MiniAppWorkspace::remove($root);
        }
    }
}
