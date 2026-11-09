<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\PredictStage;
use PeakSim\Runtime\Simulate\SimulateCommand;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\Fixtures;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\RecordingIo;
use PHPUnit\Framework\TestCase;

/** `bin/simulate` with the prediction step connected, on the made-up estimate and scenario of the first stage's tests. */
final class PredictStageTest extends TestCase
{
    /** @return array{int, string, string} */
    private static function invoke(RecordingIo $io): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $next = new PredictStage('/est', $out, $err, null, null, $io);
        $code = (new SimulateCommand($out, $err, $io, $next))->run(['--estimate=/est', '--scenario=' . Fixtures::SCENARIO_PATH, '--out=/out']);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testThePredictionIsWrittenAfterTheLoadedInputAndCarriesNothingOfObserved(): void
    {
        $io = Fixtures::io();
        [$code, $stdout, $stderr] = self::invoke($io);
        self::assertSame(0, $code, $stderr);
        $writes = array_values(array_map(static fn (string $e): string => substr($e, 6), array_filter($io->events, static fn (string $e): bool => str_starts_with($e, 'write '))));
        self::assertSame(['/out/loaded.json', '/out/prediction.json'], $writes);
        $json = $io->files['/out/prediction.json'];
        self::assertStringNotContainsString(Fixtures::TARGET_OBSERVED, $json);
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['peak-1'], array_column($report['scenarios'], 'id'));
        self::assertSame(['peak-1'], $report['low_coverage_scenarios']); // POST /m (books has no count) and GET /inc have no cost: 8 of 23 rps
        self::assertSame('one_coefficient', $report['calibration']['method']);
        self::assertSame('not_checked', $report['accuracy_on_this_app']['status']);
        self::assertStringContainsString('WARNING low_coverage', $stdout);
    }

    public function testTheMemoryLineIsShownOnlyForARouteWhoseFormulaHeld(): void
    {
        $io = Fixtures::io();
        $io->files['/est/memory-accuracy.json'] = json_encode(['schema_version' => '1', 'source' => 'test', 'within' => ['GET /a']]);
        [$code, , $stderr] = self::invoke($io);
        self::assertSame(0, $code, $stderr);
        $report = json_decode($io->files['/out/prediction.json'], true, 512, JSON_THROW_ON_ERROR);
        $a = null;
        foreach ($report['peak_memory']['routes'] as $m) {
            if ($m['route'] === 'GET /a') {
                $a = $m;
            }
        }
        // 1000 + 2 x N over 0.8 x 128M = 107,374,182.4 -> N > 53,686,591.2 -> 53,686,592
        self::assertSame(53686592, $a['n']);
        self::assertSame('shown', $a['status']);
    }

    public function testASavedSelfcheckResultIsPickedUpFromTheEstimateDirectory(): void
    {
        $io = Fixtures::io();
        $io->files['/est/selfcheck-result.json'] = json_encode(['kind' => 'selfcheck-result', 'app' => 'demo', 'target_scale' => 20000, 'checked_at' => 'x', 'summary' => ['verdict' => 'pass']]);
        [$code] = self::invoke($io);
        self::assertSame(0, $code);
        $report = json_decode($io->files['/out/prediction.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('checked', $report['accuracy_on_this_app']['status']);
    }

    public function testWithoutACalibrationScenarioItStopsAndWritesNoPrediction(): void
    {
        $io = Fixtures::io(str_replace('kind: calibration', 'kind: target', Fixtures::scenarioYaml()));
        [$code, , $stderr] = self::invoke($io);
        self::assertSame(PredictStage::EXIT_CALIBRATION, $code);
        self::assertStringContainsString('calibration', $stderr);
        self::assertArrayNotHasKey('/out/prediction.json', $io->files);
    }

    public function testAFileThatIsThereButNotValidStopsTheRunNotPassedOver(): void
    {
        $io = Fixtures::io();
        $io->files['/est/memory-accuracy.json'] = '{"within": []}';
        [$code] = self::invoke($io);
        self::assertSame(PredictStage::EXIT_ACCURACY_FILE, $code);
        self::assertArrayNotHasKey('/out/prediction.json', $io->files);

        $io = Fixtures::io();
        $io->files['/est/selfcheck-result.json'] = '{"kind": "other"}';
        [$code] = self::invoke($io);
        self::assertSame(PredictStage::EXIT_ACCURACY_FILE, $code);

        $io = Fixtures::io();
        $io->files['/est/selfcheck-result.json'] = '{not json';
        [$code] = self::invoke($io);
        self::assertSame(PredictStage::EXIT_ACCURACY_FILE, $code);
    }
}
