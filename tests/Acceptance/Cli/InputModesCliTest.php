<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Cli;

use PeakSim\Tests\Support\MiniAppWorkspace;

/**
 * The two ways in give one answer (design 3.1): reading a pre-exported directory and running artisan (here the
 * stub, which prints the same export) print the same table, and the same JSON apart from `input.mode`, which
 * says which way in was used.
 */
final class InputModesCliTest extends CliTestCase
{
    public function testTheTableIsTheSameThroughBothWaysIn(): void
    {
        $viaExport = $this->analyze($this->exportOption());
        $viaStub = $this->analyze($this->stubOption());

        $this->assertSame(0, $viaExport->exitCode, $viaExport->stderr);
        $this->assertSame(0, $viaStub->exitCode, $viaStub->stderr);
        $this->assertSame($viaExport->stdout, $viaStub->stdout);
    }

    public function testTheJsonIsTheSameThroughBothWaysInExceptForTheModeItNames(): void
    {
        $viaExport = $this->analyze($this->exportOption(), '--format=json');
        $viaStub = $this->analyze($this->stubOption(), '--format=json');

        $this->assertSame(0, $viaStub->exitCode, $viaStub->stderr);
        $export = $this->decodeJson($viaExport->stdout);
        $stub = $this->decodeJson($viaStub->stdout);
        $this->assertSame('export-dir', $export['input']['mode']);
        $this->assertSame('artisan-command', $stub['input']['mode']);
        unset($export['input'], $stub['input']);
        $this->assertSame($export, $stub);
    }

    public function testOneRouteIsTheSameThroughBothWaysIn(): void
    {
        $viaExport = $this->analyze($this->exportOption(), '--route=/hidden/cache');
        $viaStub = $this->analyze($this->stubOption(), '--route=/hidden/cache');

        $this->assertSame(0, $viaStub->exitCode, $viaStub->stderr);
        $this->assertSame($viaExport->stdout, $viaStub->stdout);
    }

    public function testAWarningOnStandardErrorOfArtisanDoesNotDisturbTheOutput(): void
    {
        putenv('PEAK_STUB_MODE=big_stderr');

        $viaStub = $this->analyze($this->stubOption());
        putenv('PEAK_STUB_MODE=ok');
        $plain = $this->analyze($this->stubOption());

        $this->assertSame(0, $viaStub->exitCode, $viaStub->stderr);
        $this->assertSame($plain->stdout, $viaStub->stdout);
    }

    public function testExportDirTakesItsValueAfterASpaceToo(): void
    {
        $withEquals = $this->analyze($this->exportOption(), '--format=json');
        $withSpace = $this->analyze('--export-dir', MiniAppWorkspace::fixtureExport(), '--format=json');

        $this->assertSame(0, $withSpace->exitCode, $withSpace->stderr);
        $this->assertSame($withEquals->stdout, $withSpace->stdout);
    }

    public function testArtisanCommandTakesItsValueAfterASpaceToo(): void
    {
        $stub = MiniAppWorkspace::projectRoot() . '/tests/Support/artisan-stub/artisan';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stub);

        $withEquals = $this->analyze($this->stubOption(), '--format=json');
        $withSpace = $this->analyze('--artisan-command', $command, '--format=json');

        $this->assertSame(0, $withSpace->exitCode, $withSpace->stderr);
        $this->assertSame($withEquals->stdout, $withSpace->stdout);
    }

    public function testTheSpaceFormMayComeBeforeTheAppRoot(): void
    {
        $result = $this->peakSim(['analyze', '--export-dir', MiniAppWorkspace::fixtureExport(), MiniAppWorkspace::fixtureApp()]);

        $this->assertSame(0, $result->exitCode, $result->stderr);
        $this->assertSame($this->analyze($this->exportOption())->stdout, $result->stdout);
    }
}
