<?php

declare(strict_types=1);

// Reads an estimate and a scenario file, calibrates, predicts every target scenario at every stage of the lab's rps ladder and writes
// `ladder-prediction.json`. No Docker, no database, no git. The two options of the step are taken out before the command reads the rest:
//   --estimate-commit=<hash>           the commit of the estimate directory (found by poc/lab/bin/predict-ladder; written as null when not given)
//   --estimate-commit-source=<source>  how it was found: "git" or "argument"
//   --safe-estimate=<dir>::<commit>::<source>  an estimate made at another count of rows, whose marks serve the scenarios at that count
//                                      (repeatable; the commit may be empty, then the source is empty too)
//   --require-marks-at-count           stop (exit 35) when a target scenario reads a count of rows that no estimate was made at
//   --expect-estimate-commit=<hash>    the commit (or its start) the main estimate should come from; the output says whether it does
//   --naive-count=<rows>               N_0, the count of rows the naive estimate is chosen at: only the calibration scenarios at exactly this
//                                      count are candidates (simulator-spec.md section 7.3). Required: with none (or no calibration scenario
//                                      at it) the step stops with exit 37 and writes no prediction

use PeakSim\Runtime\Simulate\Ladder\LadderStage;
use PeakSim\Runtime\Simulate\SimulateCommand;

require __DIR__ . '/../../bootstrap.php';

$args = [];
$estimateDir = '';
$scenarioPath = '';
$commit = null;
$source = null;
$safe = [];
$requireMarks = false;
$expected = null;
$naiveCount = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--safe-estimate=')) {
        $parts = explode('::', substr($arg, strlen('--safe-estimate=')));
        if (count($parts) !== 3 || $parts[0] === '') {
            fwrite(STDERR, "predict-ladder: --safe-estimate must be <dir>::<commit>::<source>\n");
            exit(2);
        }
        $safe[] = ['dir' => $parts[0], 'commit' => $parts[1] === '' ? null : $parts[1], 'commit_source' => $parts[2] === '' ? null : $parts[2]];
    } elseif ($arg === '--require-marks-at-count') {
        $requireMarks = true;
    } elseif (str_starts_with($arg, '--naive-count=')) {
        $given = substr($arg, strlen('--naive-count='));
        if (preg_match('/^[1-9][0-9]*$/', $given) !== 1) {
            fwrite(STDERR, "predict-ladder: --naive-count must be a positive whole number of rows\n");
            exit(2);
        }
        $naiveCount = (int) $given;
    } elseif (str_starts_with($arg, '--expect-estimate-commit=')) {
        $expected = substr($arg, strlen('--expect-estimate-commit='));
    } elseif (str_starts_with($arg, '--estimate-commit=')) {
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

if ($expected === '') {
    $expected = null;
}

exit((new SimulateCommand(STDOUT, STDERR, null, new LadderStage($estimateDir, $scenarioPath, STDOUT, STDERR, $commit, $source, null, $safe, $requireMarks, $expected, $naiveCount)))->run($args));
