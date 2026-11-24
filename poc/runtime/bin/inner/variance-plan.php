<?php

declare(strict_types=1);

// Runs INSIDE the sealed PHP container (bin/variance-run calls it). Reads a recorded task list and prints, as one JSON
// document, the databases to build and the `bin/measure-one` arguments of the routes to repeat on each (see Variance\TrialPlan).
// It builds nothing, measures nothing and writes nothing.
//
//   php variance-plan.php --tasks=/out/tasks.json --routes='DELETE /a/{b},POST /c' --scale=100000
//        [--data=generator|seeder] [--base=N] [--n-tables=a,b] [--profile=<name>] [--out-dir=/out]
//
// With --out-dir the plan is also written there as plain files (see TrialPlan::writeFiles); without it the JSON goes to stdout.
//
// Exit codes: 0 done / 2 usage or a route that cannot be planned.

use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\GeneratorPlanner;
use PeakSim\Runtime\Measure\Profile;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Measure\SeederPlanner;
use PeakSim\Runtime\Variance\TrialPlan;

require dirname(__DIR__, 2) . '/bootstrap.php';

$usage = static function (string $why): never {
    fwrite(STDERR, $why . "\nusage: variance-plan.php --tasks=<file> --routes='METHOD /uri,METHOD /uri' --scale=N [--data=generator|seeder] [--base=N] [--n-tables=a,b] [--profile=<name>]\n");
    exit(2);
};

$opts = ['data' => 'generator'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(tasks|routes|scale|data|base|n-tables|profile|out-dir)=(.*)$/Ds', $arg, $m) !== 1) {
        $usage('unknown argument: ' . $arg);
    }
    $opts[$m[1]] = $m[2];
}
if (!isset($opts['tasks'], $opts['routes'], $opts['scale'])) {
    $usage('--tasks, --routes and --scale are required');
}
if (preg_match('/^[0-9]{1,7}$/D', $opts['scale']) !== 1 || (int) $opts['scale'] < 1) {
    $usage('--scale must be a positive integer');
}
if (!in_array($opts['data'], ['generator', 'seeder'], true)) {
    $usage('--data takes generator or seeder');
}
if (isset($opts['base']) && preg_match('/^[1-9][0-9]{0,3}$/D', $opts['base']) !== 1) {
    $usage('--base must be a positive integer');
}
if (isset($opts['n-tables'])) {
    if ($opts['data'] !== 'generator') {
        $usage('--n-tables works with --data=generator only');
    }
    if (preg_match('/^[a-z0-9_]+(,[a-z0-9_]+)*$/D', $opts['n-tables']) !== 1) {
        $usage('--n-tables takes table names (lowercase letters, digits and _) separated by commas');
    }
}
if (isset($opts['profile']) && preg_match('/^[a-z][a-z0-9_-]{0,30}$/D', $opts['profile']) !== 1) {
    $usage('--profile takes the name of a file in profiles/');
}
$json = is_file($opts['tasks']) ? file_get_contents($opts['tasks']) : false;
$list = $json === false ? null : json_decode($json, true);
if (!is_array($list)) {
    $usage('--tasks is not a readable JSON list: ' . $opts['tasks']);
}

try {
    $tasks = array_map(RouteTask::fromArray(...), $list);
    $planner = $opts['data'] === 'seeder' ? new SeederPlanner() : new GeneratorPlanner(isset($opts['base']) ? (int) $opts['base'] : GeneratorPlanner::BASE, isset($opts['n-tables']) ? explode(',', $opts['n-tables']) : null);
    // the routes the profile says rewrite a table other routes read go last in their database, and their tables are ANALYZEd between laps
    $stateChanging = isset($opts['profile']) && $opts['data'] === 'generator' ? Profile::stateChanging(Profile::load($opts['profile'])) : [];
    $plan = TrialPlan::build($tasks, explode(',', $opts['routes']), (int) $opts['scale'], $planner, $opts['data'], $opts['profile'] ?? null, $stateChanging);
} catch (BuildFailure $e) {
    $usage($e->getMessage());
} catch (InvalidArgumentException $e) {
    $usage($e->getMessage());
}

if (isset($opts['out-dir'])) {
    if (!is_dir($opts['out-dir']) || !is_writable($opts['out-dir'])) {
        $usage('--out-dir is not a writable directory: ' . $opts['out-dir']);
    }
    TrialPlan::writeFiles($plan, rtrim($opts['out-dir'], '/'));
} else {
    echo json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
}
exit(0);
