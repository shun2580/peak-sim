<?php

declare(strict_types=1);

// Reads an estimate and a scenario file, calibrates, predicts every target scenario at every stage of the lab's rps ladder and writes
// `ladder-prediction.json`. No Docker, no database, no git. The two options of the step are taken out before the command reads the rest:
//   --estimate-commit=<hash>           the commit of the estimate directory (found by poc/lab/bin/predict-ladder; written as null when not given)
//   --estimate-commit-source=<source>  how it was found: "git" or "argument"

use PeakSim\Runtime\Simulate\Ladder\LadderStage;
use PeakSim\Runtime\Simulate\SimulateCommand;

require __DIR__ . '/../../bootstrap.php';

$args = [];
$estimateDir = '';
$scenarioPath = '';
$commit = null;
$source = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--estimate-commit=')) {
        $commit = substr($arg, strlen('--estimate-commit='));
    } elseif (str_starts_with($arg, '--estimate-commit-source=')) {
        $source = substr($arg, strlen('--estimate-commit-source='));
    } else {
        $args[] = $arg;
        if (str_starts_with($arg, '--estimate=')) {
            $estimateDir = substr($arg, strlen('--estimate='));
        } elseif (str_starts_with($arg, '--scenario=')) {
            $scenarioPath = substr($arg, strlen('--scenario='));
        }
    }
}
if ($commit === '') {
    $commit = null;
}

exit((new SimulateCommand(STDOUT, STDERR, null, new LadderStage($estimateDir, $scenarioPath, STDOUT, STDERR, $commit, $source)))->run($args));
