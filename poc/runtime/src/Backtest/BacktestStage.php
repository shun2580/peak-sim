<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Backtest;

use PeakSim\Runtime\Simulate\Load\LoadedInput;
use PeakSim\Runtime\Simulate\Load\LoadFailure;
use PeakSim\Runtime\Simulate\Load\NativeIo;
use PeakSim\Runtime\Simulate\Load\OrderViolation;
use PeakSim\Runtime\Simulate\Load\SimulateIo;
use PeakSim\Runtime\Simulate\Load\TwoStage;
use PeakSim\Runtime\Simulate\Predict\PredictStage;

/**
 * The `$next` of SimulateCommand for `bin/backtest` (simulator-spec.md section 8.2). The order, which a test holds:
 *
 *   1. the prediction step runs and writes `prediction.json` through {@see TwoStage::writeOutput} (the loaded input was written by stage one);
 *   2. only then {@see TwoStage::stageTwo} is asked for the target scenarios' `observed`, told to wait for `loaded.json` and `prediction.json`;
 *   3. the comparison is built and written as `backtest.json`, and printed.
 *
 * The only way to the observed values is stageTwo; this class never parses the scenario file itself.
 *
 * Exit codes: those of the prediction step when it does not finish (0 is required to go on); 40 no target scenario has an observed value;
 * 41 the order was not kept (a bug, not an input: the observed values were not read); 42 the naive estimate cannot be made; a scenario
 * file that stops the second reading gives its own LoadFailure code (20..25).
 */
final class BacktestStage
{
    public const BACKTEST_FILE = 'backtest.json';

    public const EXIT_NO_OBSERVED = 40;

    public const EXIT_ORDER = 41;

    public const EXIT_NAIVE = 42;

    /**
     * @param callable(TwoStage, LoadedInput, string): int $predict the prediction step (PredictStage); returns 0 when prediction.json is written
     * @param resource                                     $stdout
     * @param resource                                     $stderr
     */
    public function __construct(
        private readonly mixed $predict,
        private readonly string $scenarioPath,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
        private readonly ?SimulateIo $io = null,
    ) {
    }

    public function __invoke(TwoStage $stage, LoadedInput $input, string $outDir): int
    {
        $io = $this->io ?? new NativeIo();
        $dir = rtrim($outDir, '/');
        $loadedPath = $dir . '/' . TwoStage::LOADED_FILE;
        $predictionPath = $dir . '/' . PredictStage::PREDICTION_FILE;

        $code = (int) ($this->predict)($stage, $input, $outDir);
        if ($code !== 0) {
            return $code;
        }

        try {
            $observed = $stage->stageTwo($this->scenarioPath, $loadedPath, $predictionPath);
        } catch (OrderViolation $e) {
            fwrite($this->stderr, 'bin/backtest: the order was not kept, nothing was compared: ' . $e->getMessage() . "\n");

            return self::EXIT_ORDER;
        } catch (LoadFailure $e) {
            fwrite($this->stderr, 'bin/backtest: ' . $e->getMessage() . "\n");

            return $e->exitCode;
        }
        if ($observed === []) {
            fwrite($this->stderr, "bin/backtest: no target scenario has an observed value; the prediction is written, there is nothing to compare it with\n");

            return self::EXIT_NO_OBSERVED;
        }

        $prediction = json_decode($io->read($predictionPath), true, 512, JSON_THROW_ON_ERROR);
        try {
            $report = BacktestReport::build($input->toArray(), (array) $prediction, $observed);
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'bin/backtest: the naive estimate cannot be made: ' . $e->getMessage() . "\n");

            return self::EXIT_NAIVE;
        }
        $stage->writeOutput(
            $dir . '/' . self::BACKTEST_FILE,
            json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
        fwrite($this->stdout, BacktestText::render($report));

        return 0;
    }
}
