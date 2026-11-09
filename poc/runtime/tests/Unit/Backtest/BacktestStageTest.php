<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Backtest;

use PeakSim\Runtime\Backtest\BacktestReport;
use PeakSim\Runtime\Backtest\BacktestStage;
use PeakSim\Runtime\Simulate\Load\LoadedInput;
use PeakSim\Runtime\Simulate\Load\TwoStage;
use PeakSim\Runtime\Simulate\Predict\PredictStage;
use PeakSim\Runtime\Simulate\SimulateCommand;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\Fixtures;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\RecordingIo;
use PHPUnit\Framework\TestCase;

/**
 * The order of `bin/backtest` (simulator-spec.md 3.4 and 8.2): the prediction is written before a target's `observed` is read.
 * Held by the shape of the code and by these tests; each of the order tests fails when the guard is taken out or the order is turned round.
 */
final class BacktestStageTest extends TestCase
{
    private const SCENARIO = Fixtures::SCENARIO_PATH;

    private const PREDICTION = '/out/prediction.json';

    /** The calls that read the scenario file, as positions in the event list. @return list<int> */
    private static function scenarioReads(RecordingIo $io): array
    {
        return array_keys(array_filter($io->events, static fn (string $e): bool => $e === 'read ' . self::SCENARIO));
    }

    private static function position(RecordingIo $io, string $event): int|false
    {
        return array_search($event, $io->events, true);
    }

    /**
     * @param (callable(TwoStage, LoadedInput, string): int)|null $predict null: the real PredictStage
     *
     * @return array{int, string, string}
     */
    private static function backtest(RecordingIo $io, ?callable $predict = null): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $predict ??= new PredictStage('/est', $out, $err, null, null, $io);
        $next = new BacktestStage($predict, self::SCENARIO, $out, $err, $io);
        $code = (new SimulateCommand($out, $err, $io, $next))->run(['--estimate=/est', '--scenario=' . self::SCENARIO, '--out=/out']);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** A prediction step that writes a fixed prediction the way PredictStage does (through TwoStage::writeOutput). */
    private static function stubPrediction(float $point = 0.6): callable
    {
        return static function (TwoStage $stage, LoadedInput $input, string $outDir) use ($point): int {
            $stage->writeOutput(self::PREDICTION, json_encode([
                'app' => 'demo',
                'resources' => ['threshold' => 0.8],
                'scenarios' => [['id' => 'peak-1', 'rps_total' => 23.0, 'low_coverage' => false, 'utilization' => ['point' => $point, 'upper' => $point + 0.1]]],
            ], JSON_THROW_ON_ERROR));

            return 0;
        };
    }

    public function testTheObservedValuesAreReadOnlyAfterThePredictionIsWritten(): void
    {
        $io = Fixtures::io();
        [$code, , $stderr] = self::backtest($io);

        self::assertSame(0, $code, $stderr);
        $reads = self::scenarioReads($io);
        self::assertCount(2, $reads, 'the scenario file is read twice: stage one (observed cut out), then stage two (observed taken)');
        $written = self::position($io, 'write ' . self::PREDICTION);
        self::assertIsInt($written);
        self::assertLessThan($written, $reads[0], 'the first reading is before the prediction is written');
        self::assertGreaterThan($written, $reads[1], 'the second reading, the one that takes the observed values, is after the prediction is written');
        self::assertGreaterThan(self::position($io, 'write /out/loaded.json'), $reads[1]);
        // and the comparison is the last thing written
        self::assertSame('write /out/backtest.json', end($io->events));
    }

    public function testNeitherTheLoadedInputNorThePredictionCarriesTheObservedValue(): void
    {
        $io = Fixtures::io();
        self::backtest($io);

        self::assertStringNotContainsString(Fixtures::TARGET_OBSERVED, $io->files['/out/loaded.json']);
        self::assertStringNotContainsString(Fixtures::TARGET_OBSERVED, $io->files[self::PREDICTION]);
        self::assertStringContainsString(Fixtures::TARGET_OBSERVED, $io->files['/out/backtest.json'], 'only the comparison, written last, has it');
    }

    public function testAPredictionStepThatWritesNothingMeansTheObservedValuesAreNeverRead(): void
    {
        $io = Fixtures::io();
        [$code, , $stderr] = self::backtest($io, static fn (): int => 0); // says "done" without writing prediction.json

        self::assertSame(BacktestStage::EXIT_ORDER, $code);
        self::assertStringContainsString('order was not kept', $stderr);
        self::assertCount(1, self::scenarioReads($io), 'only the first reading happened');
        self::assertArrayNotHasKey('/out/backtest.json', $io->files);
    }

    public function testAPredictionWrittenAroundTheGuardDoesNotCount(): void
    {
        $io = Fixtures::io();
        $around = static function (TwoStage $stage, LoadedInput $input, string $outDir) use ($io): int {
            $io->write(self::PREDICTION, '{}'); // not through TwoStage::writeOutput

            return 0;
        };
        [$code] = self::backtest($io, $around);

        self::assertSame(BacktestStage::EXIT_ORDER, $code);
        self::assertCount(1, self::scenarioReads($io));
    }

    public function testAPredictionStepThatStopsWithACodeMeansTheObservedValuesAreNeverRead(): void
    {
        $io = Fixtures::io();
        [$code] = self::backtest($io, static fn (): int => PredictStage::EXIT_CALIBRATION);

        self::assertSame(PredictStage::EXIT_CALIBRATION, $code, 'the code of the prediction step is the code of the backtest');
        self::assertCount(1, self::scenarioReads($io));
        self::assertArrayNotHasKey('/out/backtest.json', $io->files);
    }

    public function testTheRealPredictionStepStoppingForNoCalibrationMeansTheObservedValuesAreNeverRead(): void
    {
        $io = Fixtures::io(str_replace('kind: calibration', 'kind: target', Fixtures::scenarioYaml()));
        [$code] = self::backtest($io);

        self::assertSame(PredictStage::EXIT_CALIBRATION, $code);
        self::assertCount(1, self::scenarioReads($io));
        self::assertArrayNotHasKey(self::PREDICTION, $io->files);
    }

    public function testTheComparisonIsWorkedOutFromTheObservedValueAndThePrediction(): void
    {
        $io = Fixtures::io();
        [$code, $stdout, $stderr] = self::backtest($io, self::stubPrediction(0.60));

        self::assertSame(0, $code, $stderr);
        $report = json_decode($io->files['/out/backtest.json'], true, 512, JSON_THROW_ON_ERROR);
        $row = $report['scenarios'][0];
        // calibration normal-1: rps 5 + 1 = 6, observed 0.21; target rps total 23 (the stub), observed 0.74317
        // naive = 0.21 * 23 / 6 = 0.805; naive - observed = 0.06183; prediction - observed = 0.60 - 0.74317 = -0.14317
        self::assertEqualsWithDelta(0.74317, $row['observed'], 1e-12);
        self::assertEqualsWithDelta(0.805, $row['naive']['utilization'], 1e-12);
        self::assertEqualsWithDelta(0.06183, $row['naive']['difference'], 1e-12);
        self::assertEqualsWithDelta(-0.14317, $row['predicted']['difference'], 1e-12);
        self::assertTrue($row['prediction_agrees_on_threshold'], 'predicted 0.60 and observed 0.74317 are both under 0.8');
        self::assertFalse($row['naive_agrees_on_threshold'], 'the naive 0.805 is over 0.8, the observation 0.74317 is not');
        self::assertSame(BacktestReport::SCHEMA_VERSION, $report['schema_version']);
        self::assertStringContainsString('prediction was written before the observed utilisation was read', $stdout);
        self::assertStringContainsString('peak-1', $stdout);
    }

    public function testASecondRunOnTheSameInputWritesTheSameFiles(): void
    {
        $a = Fixtures::io();
        $b = Fixtures::io();
        self::backtest($a);
        self::backtest($b);

        self::assertSame($a->files['/out/backtest.json'], $b->files['/out/backtest.json']);
        self::assertSame($a->files[self::PREDICTION], $b->files[self::PREDICTION]);
    }

    public function testNoObservedValueOnAnyTargetStopsWithTheCodeAfterThePredictionIsWritten(): void
    {
        $yaml = (string) preg_replace('/\n\s*observed: \{ db_cpu_utilization: 0\.74317 \}/', '', Fixtures::scenarioYaml());
        self::assertStringNotContainsString('0.74317', $yaml);
        $io = Fixtures::io($yaml);
        [$code, , $stderr] = self::backtest($io);

        self::assertSame(BacktestStage::EXIT_NO_OBSERVED, $code);
        self::assertStringContainsString('no target scenario has an observed value', $stderr);
        self::assertArrayHasKey(self::PREDICTION, $io->files, 'the prediction is kept');
        self::assertArrayNotHasKey('/out/backtest.json', $io->files);
    }

    public function testTheObservedValueIsOnlyAskedForThroughTheSecondStage(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Backtest/BacktestStage.php');

        self::assertSame(1, substr_count($source, '->stageTwo('), 'the one way to the observed values');
        self::assertStringNotContainsString('ScenarioReader', $source);
        self::assertStringNotContainsString('targetObserved', $source);
        self::assertStringNotContainsString('MiniYaml', $source);
        self::assertStringContainsString('$loadedPath, $predictionPath', $source, 'stageTwo is told which outputs it waits for');
        // the order in the code: the prediction step is called before stageTwo
        self::assertLessThan(strpos($source, '->stageTwo('), strpos($source, '($this->predict)('));
    }

    public function testTheBacktestCommandIsTheSimulateCommandPlusTheComparison(): void
    {
        $inner = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/inner/backtest.php');

        self::assertStringContainsString('new BacktestStage($predict, $scenarioPath', $inner);
        self::assertStringContainsString('new PredictStage(', $inner);
    }
}
