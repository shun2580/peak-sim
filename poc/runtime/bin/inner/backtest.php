<?php

declare(strict_types=1);

// Predicts like bin/simulate, then reads the observed utilisation of the target scenarios and compares (simulator-spec.md 8.2).
// The prediction (prediction.json) is written before the observed values are read; the order is kept by the code and held by tests.
// Options are those of bin/simulate (--selfcheck and --memory-accuracy belong to the prediction step).

use PeakSim\Runtime\Backtest\BacktestStage;
use PeakSim\Runtime\Simulate\Predict\PredictStage;
use PeakSim\Runtime\Simulate\SimulateCommand;

require __DIR__ . '/../../bootstrap.php';

$args = [];
$estimateDir = '';
$scenarioPath = '';
$selfcheck = null;
$memoryAccuracy = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--selfcheck=')) {
        $selfcheck = substr($arg, strlen('--selfcheck='));
    } elseif (str_starts_with($arg, '--memory-accuracy=')) {
        $memoryAccuracy = substr($arg, strlen('--memory-accuracy='));
    } else {
        $args[] = $arg;
        if (str_starts_with($arg, '--estimate=')) {
            $estimateDir = substr($arg, strlen('--estimate='));
        } elseif (str_starts_with($arg, '--scenario=')) {
            $scenarioPath = substr($arg, strlen('--scenario='));
        }
    }
}

$predict = new PredictStage($estimateDir, STDOUT, STDERR, $memoryAccuracy, $selfcheck);
exit((new SimulateCommand(STDOUT, STDERR, null, new BacktestStage($predict, $scenarioPath, STDOUT, STDERR)))->run($args));
