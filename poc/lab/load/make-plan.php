<?php

declare(strict_types=1);

/*
 * Makes the plan the k6 scenario reads (poc/lab/load/scenario.js) from the route list and a mix and a total rps.
 *
 *   php poc/lab/load/make-plan.php --routes=<route-list.json> --mix=even|first80|last80|odd80|even80|bookpage80zip0 --rps=<number> [--duration=600] [--warmup=120]
 *
 * The routes are taken in the order of the list's `selected` (its rank order); the list is read, never changed.
 * exit codes: 0 done / 2 wrong usage or an unreadable list
 */

require __DIR__ . '/../../runtime/bootstrap.php';

use PeakSim\Runtime\LabLoad\ScenarioPlan;

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) !== 1) {
        fwrite(STDERR, "make-plan: unknown argument: $arg\n");
        exit(2);
    }
    $opts[$m[1]] = $m[2];
}
foreach (['routes', 'mix', 'rps'] as $need) {
    if (!isset($opts[$need])) {
        fwrite(STDERR, "usage: make-plan --routes=<route-list.json> --mix=<even|first80|last80|odd80|even80|bookpage80zip0> --rps=<number> [--duration=600] [--warmup=120]\n");
        exit(2);
    }
}
$list = is_file($opts['routes']) ? json_decode((string) file_get_contents($opts['routes']), true) : null;
if (!is_array($list) || !isset($list['selected']) || !is_array($list['selected'])) {
    fwrite(STDERR, "make-plan: cannot read the route list {$opts['routes']}\n");
    exit(2);
}
$routes = array_map(static fn (array $s): string => (string) $s['route'], $list['selected']);

try {
    $plan = ScenarioPlan::build(
        $routes,
        $opts['mix'],
        (float) $opts['rps'],
        (int) ($opts['duration'] ?? ScenarioPlan::DURATION_SEC),
        (int) ($opts['warmup'] ?? ScenarioPlan::WARMUP_SEC),
    );
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'make-plan: ' . $e->getMessage() . "\n");
    exit(2);
}
echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
