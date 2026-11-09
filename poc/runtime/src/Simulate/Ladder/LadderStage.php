<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Ladder;

use PeakSim\Runtime\Simulate\Calibrate\Calibrator;
use PeakSim\Runtime\Simulate\Load\EstimateFormulas;
use PeakSim\Runtime\Simulate\Load\LoadedInput;
use PeakSim\Runtime\Simulate\Load\NativeIo;
use PeakSim\Runtime\Simulate\Load\SimulateIo;
use PeakSim\Runtime\Simulate\Load\TwoStage;
use PeakSim\Runtime\Simulate\Predict\PredictStage;

/**
 * The step after the first stage of `bin/simulate`'s command (the `$next` of SimulateCommand) that makes the ladder prediction of the lab
 * backtest instead of `prediction.json`: calibrate on the calibration scenarios (the same {@see Calibrator} and
 * {@see PredictStage::points} as `bin/simulate`), predict every target scenario at every stage of the ladder, write
 * `<out>/ladder-prediction.json` through {@see TwoStage::writeOutput}, and print a short summary.
 *
 * It does not read a target's `observed`. It does not call git: the commit of the estimate is given by the caller.
 *
 * Exit codes: 0 done; 30 the calibration cannot be made (the same code as `bin/simulate`); 32 the scenario file has no target scenario.
 * An output that cannot be written is the usual 1 of the command.
 */
final class LadderStage
{
    public const OUTPUT_FILE = 'ladder-prediction.json';

    public const EXIT_CALIBRATION = PredictStage::EXIT_CALIBRATION;

    public const EXIT_NO_TARGET = 32;

    /**
     * @param resource    $stdout
     * @param resource    $stderr
     * @param string|null $estimateCommit  the commit of the estimate directory as the caller found it (null: not known; it is written as null)
     * @param string|null $commitSource    how it was found ("git" or "argument"); null when the commit is null
     */
    public function __construct(
        private readonly string $estimateDir,
        private readonly string $scenarioPath,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
        private readonly ?string $estimateCommit = null,
        private readonly ?string $commitSource = null,
        private readonly ?SimulateIo $io = null,
    ) {
    }

    public function __invoke(TwoStage $stage, LoadedInput $input, string $outDir): int
    {
        $io = $this->io ?? new NativeIo();
        $loaded = $input->toArray();
        try {
            $calibration = (new Calibrator())->calibrate(PredictStage::points($loaded));
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'predict-ladder: the calibration cannot be made: ' . $e->getMessage() . "\n");

            return self::EXIT_CALIBRATION;
        }
        $inputs = [
            'estimate_commit' => $this->estimateCommit,
            'estimate_commit_source' => $this->estimateCommit === null ? null : $this->commitSource,
            'estimate_sha256' => hash('sha256', $io->read(rtrim($this->estimateDir, '/') . '/' . EstimateFormulas::FILE)),
            'scenario_sha256' => hash('sha256', $io->read($this->scenarioPath)),
        ];
        try {
            $report = LadderPrediction::build($loaded, $calibration, $inputs);
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'predict-ladder: ' . $e->getMessage() . "\n");

            return self::EXIT_NO_TARGET;
        }
        $stage->writeOutput(
            rtrim($outDir, '/') . '/' . self::OUTPUT_FILE,
            json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
        fwrite($this->stdout, "\n" . self::summary($report));

        return 0;
    }

    /** @param array<string, mixed> $report */
    public static function summary(array $report): string
    {
        $out = sprintf(
            "ladder prediction: %d stage(s) x %d scenario(s), calibration %s\n",
            count($report['stages_rps_total']),
            count($report['scenarios']),
            (string) $report['calibration']['method'],
        );
        foreach ($report['scenarios'] as $s) {
            $limit = static fn (array $l): string => $l['rps'] === null ? 'none' : sprintf('%.4f', $l['rps']);
            $out .= sprintf(
                "  %s: limit rps point %s / safe %s / naive %s\n",
                $s['id'],
                $limit($s['limit_rps']['point']),
                $limit($s['limit_rps']['safe']),
                $limit($s['limit_rps']['naive']),
            );
        }

        return $out;
    }
}
