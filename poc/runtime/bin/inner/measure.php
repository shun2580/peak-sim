<?php

declare(strict_types=1);

// Runs INSIDE the sealed PHP container (started by bin/measure). The driver of a whole measurement:
// inspect the app, then for each database a route needs: build it, measure the routes on it, drop it. Writes the
// record into PEAK_MEASURE_OUT_DIR (default /out) and prints a short summary.
//
//   php measure.php [--data=generator|seeder] [--only=a,b] [--n-tables=a,b] [--base=N] [--profile=<name>] [--gate-bypass=on|off] [--warm=on|off] [--expected=/out/expected.json] [--tap-dir=<dir>] [--analyze-after-build=on|off]
//
// Input comes from PEAK_MEASURE_* variables (MeasureConfig). Exit codes: see Measure\MeasureExit.

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\Driver;
use PeakSim\Runtime\Measure\GeneratorPlanner;
use PeakSim\Runtime\Measure\MeasureExit;
use PeakSim\Runtime\Explain\ExplainDb;
use PeakSim\Runtime\Explain\PdoStatsDb;
use PeakSim\Runtime\Explain\StatsStatements;
use PeakSim\Runtime\Measure\AnalyzingDatabaseBuilder;
use PeakSim\Runtime\Measure\DatabasePoint;
use PeakSim\Runtime\Measure\Options;
use PeakSim\Runtime\Measure\OutputWriter;
use PeakSim\Runtime\Measure\Proc;
use PeakSim\Runtime\Measure\Profile;
use PeakSim\Runtime\Measure\ProcessDatabaseBuilder;
use PeakSim\Runtime\Measure\ProcessOneRunner;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Measure\SeederPlanner;
use PeakSim\Runtime\Report\Comparison;
use PeakSim\Runtime\Run\Requester;

require dirname(__DIR__, 2) . '/bootstrap.php';

$started = microtime(true);
$stop = static function (int $code, string $message): never {
    fwrite(STDERR, $message . "\n");
    exit($code);
};

try {
    $options = Options::parse(array_slice($argv, 1));
} catch (InvalidArgumentException $e) {
    $stop(MeasureExit::USAGE, $e->getMessage());
}

try {
    $env = [];
    foreach (getenv() as $key => $value) {
        $env[(string) $key] = (string) $value;
    }
    if (($env['PEAK_MEASURE_DB_TAG'] ?? '') !== '') {
        $stop(MeasureExit::REFUSED, 'refused: PEAK_MEASURE_DB_TAG is set by the driver for its children; it is not an input of bin/measure');
    }
    // The children that start the app (bin/measure-one) learn the profile's name here, to run its `on_boot` function.
    if ($options->profile !== null) {
        $env[Profile::NAME_ENV] = $options->profile;
    }
    $config = MeasureConfig::fromEnv($env);
    if ($config->staticJson === null) {
        $stop(MeasureExit::USAGE, 'PEAK_MEASURE_STATIC_JSON must name the static analysis output');
    }
} catch (MeasureRefused $e) {
    $stop(MeasureRefused::EXIT_CODE, $e->getMessage());
}

$bin = dirname(__DIR__);
$planner = $options->data === 'seeder' ? new SeederPlanner() : new GeneratorPlanner($options->base, $options->nTables === [] ? null : $options->nTables);
$builder = new ProcessDatabaseBuilder($bin . '/inner/build-db.php', $env, $options->data, ProcessDatabaseBuilder::TIMEOUT, PHP_BINARY, $options->profile);
$scales = $config->scales;

// 1. Inspect: build a small whole system, start the app on it, turn every route into a task.
$inspection = $planner->inspectionPoint($scales);
try {
    $builder->build($inspection);
    [$code, $out, $err] = Proc::run(
        [PHP_BINARY, $bin . '/inner/inspect.php', ...$options->inspectionArguments($inspection->scale)],
        ['PEAK_MEASURE_DB_TAG' => $inspection->tag] + $env,
        300,
    );
} catch (BuildFailure $e) {
    $builder->drop($inspection);
    $stop(MeasureExit::DATA, 'could not build the inspection database: ' . $e->getMessage());
}
$builder->drop($inspection);
if ($code !== 0) {
    $stop($code === 124 ? MeasureExit::APP_START : $code, 'inspection failed (exit ' . $code . '): ' . trim($err));
}
$inspected = json_decode(trim($out), true);
if (!is_array($inspected) || !isset($inspected['tasks'])) {
    $stop(MeasureExit::APP_START, 'the inspection printed no tasks');
}
$tasks = array_map(RouteTask::fromArray(...), $inspected['tasks']);
fwrite(STDERR, sprintf("inspected: %d routes (%d not controllers), Laravel %s\n", count($tasks), $inspected['skipped'], $inspected['laravel']));

// 2. Measure.
$requestLimit = Requester::timeLimit($env[Requester::TIME_LIMIT_ENV] ?? '');
// A measurement is a warm-up, a cold and a warm request, each within the limit, plus the start of the app.
$runner = new ProcessOneRunner($bin . '/measure-one', $env, ProcessOneRunner::timeoutFor($requestLimit));
// With --analyze-after-build=on (bin/capture only) every database is analysed right after it is built, before a route is sent.
$driverBuilder = $options->analyzeAfterBuild
    ? new AnalyzingDatabaseBuilder($builder, static function (DatabasePoint $point) use ($env): StatsStatements {
        $c = MeasureConfig::fromEnv(['PEAK_MEASURE_DB_TAG' => $point->tag] + $env);

        return new StatsStatements(new PdoStatsDb(ExplainDb::open($c, $c->dbNameForScale($point->scale))));
    })
    : $builder;
// The routes the profile says rewrite a table other routes read are measured after every other route of their database (RouteOrder).
$lastRoutes = $options->profile === null ? [] : array_keys(Profile::stateChanging(Profile::load($options->profile)));
$driver = new Driver($planner, $driverBuilder, $runner, $options->gateBypass, $options->warm, $options->tapDir, $lastRoutes);
$result = $driver->run($tasks, $scales);

// 3. Record.
$expected = $options->expected === null ? null : Comparison::loadExpected($options->expected);
$meta = [
    'data' => $options->data,
    'profile' => $options->profile,
    'gate_bypass_retry' => $options->gateBypass,
    'scales' => $scales,
    'run_id' => $config->runId,
    'laravel' => $inspected['laravel'],
    'php' => $inspected['php'],
    'request_time_limit_seconds' => $requestLimit,
    'seconds' => round(microtime(true) - $started, 1),
];
$files = (new OutputWriter($config->outDir))->write($result, $meta, $tasks, $expected);

$summary = $result->report->summary();
echo sprintf("routes %d, tried %d, %.0f s; record in %s\n", $summary['routes'], $summary['tried'], microtime(true) - $started, $config->outDir);
foreach ($summary['verdicts'] as $verdict => $n) {
    echo sprintf("  %-14s %d\n", $verdict, $n);
}
foreach ($summary['relations'] as $relation => $n) {
    echo sprintf("  vs static %-16s %d\n", $relation, $n);
}
if ($summary['conflicts'] !== []) {
    echo "CONFLICTS: " . implode(', ', $summary['conflicts']) . "\n";
}
if ($result->message !== null) {
    fwrite(STDERR, 'stopped: ' . $result->message . "\n");
}
exit($result->exit);
