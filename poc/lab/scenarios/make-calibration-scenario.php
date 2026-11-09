<?php

declare(strict_types=1);

// Writes the calibration-only scenario file (simulator-spec.md section 3.4) from the plan and the record of each calibration run of the lab.
// Reads those files only; touches no database and no app. Writes the scenario file to the standard output.
//
//   php make-calibration-scenario.php --run=<id>:<dir> [--run=<id>:<dir> ...] [--scale=10000] [--tables=entities,images,references] [--cores=2]
//
// <dir> holds plan.json and record.json of that run. The runs are written in the order given.
// exit codes: 0 done / 2 wrong usage, an unreadable input, or a run that cannot be used (its rps did not come out)

require __DIR__ . '/CalibrationScenario.php';

use PeakSim\Lab\Scenarios\CalibrationScenario;

$usage = 'usage: make-calibration-scenario --run=<id>:<dir> [--run=<id>:<dir> ...] [--scale=N] [--tables=a,b,c] [--cores=N]';
$runsGiven = [];
$scale = CalibrationScenario::DEFAULT_SCALE;
$tables = CalibrationScenario::DEFAULT_TABLES;
$cores = CalibrationScenario::DEFAULT_CORES;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('~^--run=([^:]+):(.+)$~', $arg, $m) === 1) {
        $runsGiven[] = [$m[1], $m[2]];
    } elseif (preg_match('~^--scale=([0-9]+)$~', $arg, $m) === 1) {
        $scale = (int) $m[1];
    } elseif (preg_match('~^--cores=([0-9]+)$~', $arg, $m) === 1) {
        $cores = (int) $m[1];
    } elseif (preg_match('~^--tables=([a-z0-9_]+(,[a-z0-9_]+)*)$~', $arg, $m) === 1) {
        $tables = explode(',', $m[1]);
    } else {
        fwrite(STDERR, "make-calibration-scenario: unknown argument: $arg\n$usage\n");
        exit(2);
    }
}
if ($runsGiven === []) {
    fwrite(STDERR, "$usage\n");
    exit(2);
}

$runs = [];
foreach ($runsGiven as [$id, $dir]) {
    $run = ['id' => $id];
    foreach (['plan', 'record'] as $kind) {
        $path = rtrim($dir, '/') . '/' . $kind . '.json';
        $contents = is_file($path) ? file_get_contents($path) : false;
        $data = $contents === false ? null : json_decode($contents, true);
        if (!is_array($data)) {
            fwrite(STDERR, "make-calibration-scenario: cannot read $kind.json of the run $id (looked in $dir)\n");
            exit(2);
        }
        $run[$kind] = $data;
        $run[$kind . '_sha256'] = hash('sha256', $contents);
    }
    $runs[] = $run;
}

try {
    echo CalibrationScenario::build($runs, $scale, $tables, $cores);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, 'make-calibration-scenario: ' . $e->getMessage() . "\n");
    exit(2);
}
