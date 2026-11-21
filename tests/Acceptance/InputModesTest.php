<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance;

use PeakSim\Output\JsonRenderer;
use PeakSim\Tests\Support\ExpectationFixtureRows;
use PeakSim\Tests\Support\MiniAppAnalyzer;
use PeakSim\Tests\Support\MiniAppRealAnalyzer;
use PeakSim\Tests\Support\MiniAppWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * The two ways in give one answer (design 3.1): the pre-exported JSON (ExportDirSource) and a real run of artisan
 * (CommandArtisanSource), here against the stub artisan of tests/Support/artisan-stub, which prints the same
 * export. The whole report must be byte-identical. Because the stub needs no Laravel, this runs on PHP 8.2 too,
 * with nothing skipped.
 */
final class InputModesTest extends TestCase
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

    protected function analyzer(): MiniAppAnalyzer
    {
        return new MiniAppRealAnalyzer();
    }

    private function stubCommand(): string
    {
        $stub = MiniAppWorkspace::projectRoot() . '/tests/Support/artisan-stub/artisan';
        $this->assertFileExists($stub, 'the stub artisan (worker H2) is not there yet');

        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stub);
    }

    public function testTheExportDirAndTheStubArtisanGiveTheSameBytes(): void
    {
        $app = MiniAppWorkspace::fixtureApp();
        $fromExport = $this->analyzer()->json($app, MiniAppWorkspace::fixtureExport());
        $fromCommand = $this->analyzer()->json($app, null, ['artisan_command' => $this->stubCommand()]);

        $this->assertNotSame('', $fromExport);
        $this->assertSame($fromExport, $fromCommand);
    }

    /**
     * The document the CLI prints: it says how the app was read ("input.mode"), and that is the only thing that may
     * differ. Everything else, key for key, must be the same.
     */
    public function testTheRenderedJsonDiffersBetweenTheInputsOnlyInInputMode(): void
    {
        $app = MiniAppWorkspace::fixtureApp();
        $viaExport = $this->analyzer()->renderedJson($app, MiniAppWorkspace::fixtureExport(), JsonRenderer::MODE_EXPORT_DIR);
        $viaCommand = $this->analyzer()->renderedJson($app, null, JsonRenderer::MODE_ARTISAN_COMMAND, ['artisan_command' => $this->stubCommand()]);

        $export = json_decode($viaExport, true, 512, JSON_THROW_ON_ERROR);
        $command = json_decode($viaCommand, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('export-dir', $export['input']['mode']);
        $this->assertSame('artisan-command', $command['input']['mode']);
        unset($export['input'], $command['input']);
        $this->assertNotSame([], $export['routes']);
        $this->assertSame($export, $command);
        $this->assertSame(
            str_replace('"mode": "export-dir"', '"mode": "artisan-command"', $viaExport),
            $viaCommand,
            'apart from input.mode the two documents must be the same bytes'
        );
    }

    public function testBothInputsReachAllTwentyFourRoutes(): void
    {
        $app = MiniAppWorkspace::fixtureApp();
        $viaExport = $this->analyzer()->analyzeAll($app, MiniAppWorkspace::fixtureExport());
        $viaCommand = $this->analyzer()->analyzeAll($app, null, ['artisan_command' => $this->stubCommand()]);

        $expected = array_keys(ExpectationFixtureRows::rows());
        $this->assertSame([], array_values(array_diff($expected, array_keys($viaExport))), 'routes missing with the export dir');
        $this->assertSame([], array_values(array_diff($expected, array_keys($viaCommand))), 'routes missing with the stub artisan');
        $this->assertSame($viaExport, $viaCommand);
    }
}
