<?php

declare(strict_types=1);

// Reads an estimate and a scenario file, writes the loaded input, calibrates, predicts and writes the prediction (prediction.json) and a table.
// No Docker, no database.

use PeakSim\Runtime\Simulate\Predict\PredictStage;
use PeakSim\Runtime\Simulate\SimulateCommand;

require __DIR__ . '/../../bootstrap.php';

$args = array_slice($argv, 1);
$estimateDir = '';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--estimate=')) {
        $estimateDir = substr($arg, strlen('--estimate='));
    }
}

exit((new SimulateCommand(STDOUT, STDERR, null, new PredictStage($estimateDir, STDOUT, STDERR)))->run($args));
