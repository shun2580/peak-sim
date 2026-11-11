<?php

declare(strict_types=1);

// The gate in front of a calibration measurement (docs/simulator-spec.md section 7.4.1): from the plan of each planned calibration run, its count of
// rows and the estimate alone, how well can the two coefficients be told apart for the whole set and for every set that leaves one run out?
// Reads files only; touches no database, no app and no container. No utilization is read (there is none yet).
//
//   php calibration-gate.php --estimate=<estimate dir> --run=<id>:<plan.json> [--run=<id>:<plan.json> ...] [--scale=10000]
//       [--run-scale=<id>:<n> ...] [--tables=entities,images,references] [--cores=2] [--out=<file.json>]
//
// <plan.json> is the plan of the run as `load/make-plan.php` writes it (one rate per route). --scale is the count of rows of the N tables for every
// run with no --run-scale of its own. The rates of queries and rows come from the estimate's formulas put at the run's own counts, through the
// same parts that `bin/simulate` uses for the fit, and the degree is worked out by CalibrationGate (its comment says how).
// Prints the table of degrees; --out also writes it as JSON, the record of the gate.
// exit codes: 0 every set is at or above the threshold / 14 refused: a set is below it (nothing may be measured) /
// 2 wrong usage or an unreadable plan / 20..25 the estimate or a route is not usable (the codes of bin/simulate's first stage)

require __DIR__ . '/CalibrationScenario.php';
require __DIR__ . '/../../runtime/bootstrap.php';

use PeakSim\Lab\Scenarios\CalibrationScenario;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationGate;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationPoint;
use PeakSim\Runtime\Simulate\Load\InputLoader;
use PeakSim\Runtime\Simulate\Load\LoadFailure;
use PeakSim\Runtime\Simulate\Load\NativeIo;

const EXIT_REFUSED = 14;

$usage = 'usage: calibration-gate --estimate=<estimate dir> --run=<id>:<plan.json> [--run=<id>:<plan.json> ...] [--scale=N] [--run-scale=<id>:<n> ...] [--tables=a,b,c] [--cores=N] [--out=<file.json>]';
$estimateDir = null;
$runsGiven = [];
$runScales = [];
$scale = CalibrationScenario::DEFAULT_SCALE;
$tables = CalibrationScenario::DEFAULT_TABLES;
$cores = CalibrationScenario::DEFAULT_CORES;
$outFile = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('~^--estimate=(.+)$~', $arg, $m) === 1) {
        $estimateDir = $m[1];
    } elseif (preg_match('~^--run=([^:]+):(.+)$~', $arg, $m) === 1) {
        $runsGiven[] = [$m[1], $m[2]];
    } elseif (preg_match('~^--run-scale=([^:]+):([0-9]+)$~', $arg, $m) === 1) {
        if (isset($runScales[$m[1]])) {
            fwrite(STDERR, "calibration-gate: --run-scale is given twice for {$m[1]}\n$usage\n");
            exit(2);
        }
        $runScales[$m[1]] = (int) $m[2];
    } elseif (preg_match('~^--scale=([0-9]+)$~', $arg, $m) === 1) {
        $scale = (int) $m[1];
    } elseif (preg_match('~^--cores=([0-9]+)$~', $arg, $m) === 1) {
        $cores = (int) $m[1];
    } elseif (preg_match('~^--tables=([a-z0-9_]+(,[a-z0-9_]+)*)$~', $arg, $m) === 1) {
        $tables = explode(',', $m[1]);
    } elseif (preg_match('~^--out=(.+)$~', $arg, $m) === 1) {
        $outFile = $m[1];
    } else {
        fwrite(STDERR, "calibration-gate: unknown argument: $arg\n$usage\n");
        exit(2);
    }
}
if ($estimateDir === null || $runsGiven === [] || $scale < 1 || $cores < 1) {
    fwrite(STDERR, "$usage\n");
    exit(2);
}
$given = array_column($runsGiven, 0);
foreach (array_keys($runScales) as $id) {
    if (!in_array((string) $id, $given, true)) {
        fwrite(STDERR, "calibration-gate: --run-scale names a run that was not given: $id\n$usage\n");
        exit(2);
    }
}

$scenarios = [];
$seen = [];
foreach ($runsGiven as [$id, $planPath]) {
    if (isset($seen[$id])) {
        fwrite(STDERR, "calibration-gate: the run id $id is given twice\n");
        exit(2);
    }
    $seen[$id] = true;
    if (in_array($id, CalibrationScenario::NOT_FOR_CALIBRATION, true)) {
        fwrite(STDERR, "calibration-gate: the run $id is a record only; it is never a calibration run\n");
        exit(2);
    }
    $contents = is_file($planPath) ? file_get_contents($planPath) : false;
    $plan = $contents === false ? null : json_decode($contents, true);
    if (!is_array($plan)) {
        fwrite(STDERR, "calibration-gate: cannot read the plan of the run $id ($planPath)\n");
        exit(2);
    }
    try {
        $rps = CalibrationScenario::rpsOfPlan($id, $plan);
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, 'calibration-gate: ' . $e->getMessage() . "\n");
        exit(2);
    }
    $own = $runScales[$id] ?? $scale;
    if ($own < 1) {
        fwrite(STDERR, "calibration-gate: the scale of $id must be a positive whole number\n");
        exit(2);
    }
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = $own;
    }
    $scenarios[] = ['id' => $id, 'kind' => 'calibration', 'tables' => $counts, 'rps' => $rps];
}

try {
    $loaded = (new InputLoader(new NativeIo()))
        ->loadParsed($estimateDir, ['resources' => ['cores' => $cores, 'threshold' => 0.8, 'php_memory_limit_bytes' => null], 'scenarios' => $scenarios])
        ->toArray();
} catch (LoadFailure $e) {
    fwrite(STDERR, 'calibration-gate: ' . $e->getMessage() . "\n");
    exit($e->exitCode);
}

// the rates are those of the fit: CalibrationPoint::rates over the routes of each run at the run's own counts (no utilization: 0.0 is a placeholder the gate never reads)
$points = [];
foreach ($loaded['scenarios'] as $s) {
    $points[] = CalibrationPoint::fromRoutes((string) $s['id'], (float) $cores, 0.0, $s['routes']);
}
$result = CalibrationGate::evaluate($points);
$result['inputs'] = array_map(
    static fn (array $s): array => ['id' => $s['id'], 'tables' => $s['tables'], 'plan_sha256' => hash_file('sha256', $runsGiven[array_search($s['id'], $given, true)][1])],
    $scenarios,
);

echo 'calibration gate: threshold ' . sprintf('%.2f', $result['threshold']) . ', degree = the smallest singular value of the design matrix (queries, rows) with each column scaled to length 1', "\n";
foreach ($result['scenarios'] as $s) {
    echo sprintf(
        "  %-8s queries/s %.6g  rows/s %.6g  rows per query %s%s\n",
        $s['id'],
        $s['query_rate'],
        $s['row_rate'],
        $s['rows_per_query'] === null ? '-' : sprintf('%.4f', $s['rows_per_query']),
        $s['unknown_cost_routes'] > 0 ? '  (' . $s['unknown_cost_routes'] . ' route(s) of unknown cost left out)' : '',
    );
}
foreach ($result['sets'] as $set) {
    echo sprintf(
        "  %-22s degree %.6f  %s  (%s)\n",
        $set['left_out'] === null ? 'all runs' : 'without ' . $set['left_out'],
        $set['degree'],
        $set['passed'] ? 'ok' : 'BELOW',
        implode(', ', $set['ids']),
    );
}
echo sprintf("  smallest degree %.6f (%s)\n", $result['min_degree'], $result['min_left_out'] === null ? 'all runs' : 'without ' . $result['min_left_out']);

$json = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if ($outFile !== null) {
    $dir = dirname($outFile);
    if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) || @file_put_contents($outFile, $json) === false) {
        fwrite(STDERR, "calibration-gate: cannot write $outFile\n");
        exit(2);
    }
}

if (!$result['passed']) {
    fwrite(STDERR, sprintf(
        "CALIBRATION GATE REFUSED: the smallest degree %.6f (%s) is below %.2f; the set of runs cannot tell the two coefficients apart. Nothing may be measured.\n",
        $result['min_degree'],
        $result['min_left_out'] === null ? 'all runs' : 'without ' . $result['min_left_out'],
        $result['threshold'],
    ));
    exit(EXIT_REFUSED);
}
echo sprintf("CALIBRATION GATE OK: the smallest degree %.6f is at or above %.2f\n", $result['min_degree'], $result['threshold']);
exit(0);
