<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

use PeakSim\Runtime\Simulate\Calibrate\CalibrationPoint;
use PeakSim\Runtime\Simulate\Calibrate\Calibrator;
use PeakSim\Runtime\Simulate\Load\EstimateFormulas;
use PeakSim\Runtime\Simulate\Load\LoadedInput;
use PeakSim\Runtime\Simulate\Load\NativeIo;
use PeakSim\Runtime\Simulate\Load\SimulateIo;
use PeakSim\Runtime\Simulate\Load\TwoStage;

/**
 * The step after the first stage of `bin/simulate` (the `$next` of SimulateCommand): calibrate on the calibration scenarios, predict the
 * target scenarios, write `<out>/prediction.json` through {@see TwoStage::writeOutput} (so it is on record that it was written before any
 * `observed` is read), and print the table.
 *
 * It does not read a target's `observed`: it has only the loaded input, which has none.
 *
 * Exit codes: 0 done; 30 the calibration cannot be made (no calibration scenario, or an input the fit refuses); 31 the memory-accuracy
 * file or the selfcheck result is there but is not valid. An output that cannot be written is the usual 1 of the command.
 */
final class PredictStage
{
    public const PREDICTION_FILE = 'prediction.json';

    public const EXIT_CALIBRATION = 30;

    public const EXIT_ACCURACY_FILE = 31;

    /**
     * @param resource    $stdout
     * @param resource    $stderr
     * @param string|null $memoryAccuracyPath `memory-accuracy.json` of the estimate directory when null
     * @param string|null $selfcheckPath      `selfcheck-result.json` of the estimate directory when null
     */
    public function __construct(
        private readonly string $estimateDir,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
        private readonly ?string $memoryAccuracyPath = null,
        private readonly ?string $selfcheckPath = null,
        private readonly ?SimulateIo $io = null,
    ) {
    }

    public function __invoke(TwoStage $stage, LoadedInput $input, string $outDir): int
    {
        $io = $this->io ?? new NativeIo();
        $loaded = $input->toArray();
        try {
            $calibration = (new Calibrator())->calibrate(self::points($loaded));
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'bin/simulate: the calibration cannot be made: ' . $e->getMessage() . "\n");

            return self::EXIT_CALIBRATION;
        }

        $dir = rtrim($this->estimateDir, '/');
        try {
            $estimate = EstimateFormulas::load($io, $dir);
            $memoryPath = $this->memoryAccuracyPath ?? $dir . '/' . MemoryAccuracy::FILE;
            $memoryAccuracy = $io->exists($memoryPath) ? MemoryAccuracy::fromArray(self::json($io->read($memoryPath), $memoryPath)) : MemoryAccuracy::none();
            $selfcheckPath = $this->selfcheckPath ?? $dir . '/' . AccuracyRecord::FILE;
            $accuracy = $io->exists($selfcheckPath) ? AccuracyRecord::fromResult(self::json($io->read($selfcheckPath), $selfcheckPath)) : AccuracyRecord::notChecked('no selfcheck result at ' . basename($selfcheckPath));
        } catch (\InvalidArgumentException | \JsonException $e) {
            fwrite($this->stderr, 'bin/simulate: ' . $e->getMessage() . "\n");

            return self::EXIT_ACCURACY_FILE;
        }

        $report = PredictionReport::build(
            $loaded,
            $calibration,
            EstimateAdapter::peakMemoryFormulas($estimate),
            $memoryAccuracy,
            $accuracy,
            EstimateAdapter::evaluator($estimate),
        );
        $stage->writeOutput(
            rtrim($outDir, '/') . '/' . self::PREDICTION_FILE,
            json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
        fwrite($this->stdout, "\n" . PredictionText::render($report));

        return 0;
    }

    /** @return array<string, mixed> */
    private static function json(string $raw, string $path): array
    {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('not a JSON object: ' . $path);
        }

        return $data;
    }

    /**
     * The calibration scenarios of the loaded input as calibration points.
     *
     * @param array<string, mixed> $loaded
     *
     * @return list<CalibrationPoint>
     */
    public static function points(array $loaded): array
    {
        $cores = (float) $loaded['resources']['cores'];
        $points = [];
        foreach ((array) $loaded['scenarios'] as $s) {
            if (($s['kind'] ?? null) === 'calibration') {
                $points[] = CalibrationPoint::fromRoutes((string) $s['id'], $cores, (float) $s['observed']['db_cpu_utilization'], $s['routes']);
            }
        }

        return $points;
    }
}
