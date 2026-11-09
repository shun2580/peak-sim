<?php

declare(strict_types=1);

// Reads an estimate and a scenario file, writes the loaded input, calibrates, predicts and writes the prediction (prediction.json) and a table.
// No Docker, no database. Two options belong to the prediction step and are taken out before the command reads the rest:
//   --selfcheck=<selfcheck-result.json>   the saved result of bin/selfcheck, shown as "accuracy on this app" (default: <estimate dir>/selfcheck-result.json)
//   --memory-accuracy=<memory-accuracy.json>   the routes whose peak memory held (default: <estimate dir>/memory-accuracy.json)

use PeakSim\Runtime\Simulate\Predict\PredictStage;
use PeakSim\Runtime\Simulate\SimulateCommand;

require __DIR__ . '/../../bootstrap.php';

$args = [];
$estimateDir = '';
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
        }
    }
}

exit((new SimulateCommand(STDOUT, STDERR, null, new PredictStage($estimateDir, STDOUT, STDERR, $memoryAccuracy, $selfcheck)))->run($args));
