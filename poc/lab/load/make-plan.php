<?php

declare(strict_types=1);

/*
 * Makes the plan the k6 scenario reads (poc/lab/load/scenario.js) from the route list and a mix and a total rps.
 *
 *   php poc/lab/load/make-plan.php --routes=<route-list.json> --mix=even|first80|last80|odd80|even80|places1235|places1345|bookpage80zip0 --rps=<number> [--duration=600] [--warmup=120] [--allocation=<allocation.json>]
 *
 * --allocation=<file> (B at a count where some routes cannot run to the end; decisions.md D226, D227): a JSON object with `rps_after` (route => rps, the
 * prediction's allocation for the scenario) and optionally `dropped` (the routes taken out). The shares are then those of `rps_after`, not the mix's, and
 * a route of the list that is not in it is not sent. Without the option the plan is exactly what it always was.
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
        fwrite(STDERR, "usage: make-plan --routes=<route-list.json> --mix=<even|first80|last80|odd80|even80|places1235|places1345|bookpage80zip0> --rps=<number> [--duration=600] [--warmup=120]\n");
        exit(2);
    }
}
$list = is_file($opts['routes']) ? json_decode((string) file_get_contents($opts['routes']), true) : null;
if (!is_array($list) || !isset($list['selected']) || !is_array($list['selected'])) {
    fwrite(STDERR, "make-plan: cannot read the route list {$opts['routes']}\n");
    exit(2);
}
$routes = array_map(static fn (array $s): string => (string) $s['route'], $list['selected']);
$allocation = null;
if (isset($opts['allocation'])) {
    $fileAllocation = is_file($opts['allocation']) ? json_decode((string) file_get_contents($opts['allocation']), true) : null;
    if (!is_array($fileAllocation)) {
        fwrite(STDERR, "make-plan: cannot read the allocation {$opts['allocation']}\n");
        exit(2);
    }
    $allocation = [
        'rps_after' => $fileAllocation['rps_after'] ?? null,
        'dropped' => is_array($fileAllocation['dropped'] ?? null) ? $fileAllocation['dropped'] : [],
    ];
}

try {
    $plan = ScenarioPlan::build(
        $routes,
        $opts['mix'],
        (float) $opts['rps'],
        (int) ($opts['duration'] ?? ScenarioPlan::DURATION_SEC),
        (int) ($opts['warmup'] ?? ScenarioPlan::WARMUP_SEC),
        $allocation,
    );
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'make-plan: ' . $e->getMessage() . "\n");
    exit(2);
}
echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
