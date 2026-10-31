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

    public function testWhenArtisanCannotDescribeAModelTheRoutesTurnUnknownAndTheReasonIsOnStandardError(): void
    {
        $dir = MiniAppWorkspace::make('cli-no-db');
        try {
            $artisan = $dir . '/artisan';
            file_put_contents($artisan, '<?php
if (($argv[1] ?? "") === "route:list") {
    readfile(' . var_export(MiniAppWorkspace::fixtureExport() . '/route-list.json', true) . ');
    exit(0);
}
fwrite(STDERR, "SQLSTATE[HY000] [2002] Connection refused");
exit(1);
');
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($artisan);
            $result = $this->analyze('--artisan-command=' . $command, '--format=json');

            $this->assertSame(0, $result->exitCode, $result->stderr);
            $this->assertStringContainsString('note:', $result->stderr);
            $this->assertStringContainsString('model:show', $result->stderr);
            $doc = $this->decodeJson($result->stdout);
            $codes = array_merge(...array_map(static fn (array $r): array => array_column($r['gaps'], 'code'), $doc['routes']));
            $this->assertContains('MODEL_INFO_UNAVAILABLE', $codes, 'a model that could not be described is a gap, not a guess');
            $this->assertStringNotContainsString('Connection refused', $result->stdout, 'standard output holds the report only');
        } finally {
            MiniAppWorkspace::remove($dir);
        }
    }
}
