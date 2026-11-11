<?php

declare(strict_types=1);

/*
 * Turns one scenario's raw records into the scenario's record: did the rps come out (within 5%), and what was the DB's
 * utilization over the kept window. It judges nothing else.
 *
 *   php poc/lab/load/summarize-run.php --plan=<plan.json> --k6-summary=<summary.json> --samples=<cpu samples file>
 *       [--app-samples=<app cpu samples file> [--app-cpus=6]]
 *
 * The kept window is [scenario start + discarded start, scenario start + length], taken from the scenario's own start time.
 * If the samples cannot give the utilization (cpu.stat unreadable, window not covered) it exits 3 and prints no figure.
 * With --app-samples (the app container's cpu.stat, sampled the same way) the record also holds the app's CPU utilization over
 * the same window, taken over its --app-cpus (default 6); the DB figure is the one the judgment uses, the app's is read beside it.
 * exit codes: 0 done / 2 wrong usage or an unreadable input / 3 the utilization cannot be worked out
 */

require __DIR__ . '/../../runtime/bootstrap.php';

use PeakSim\Runtime\LabLoad\CpuStatUnreadable;
use PeakSim\Runtime\LabLoad\DbUtilization;
use PeakSim\Runtime\LabLoad\RpsAchievement;

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/', $arg, $m) !== 1) {
        fwrite(STDERR, "summarize-run: unknown argument: $arg\n");
        exit(2);
    }
    $opts[$m[1]] = $m[2];
}
$read = static function (string $key) use ($opts): string {
    if (!isset($opts[$key]) || !is_file($opts[$key])) {
        fwrite(STDERR, "usage: summarize-run --plan=<f> --k6-summary=<f> --samples=<f> (missing or unreadable: $key)\n");
        exit(2);
    }

    return (string) file_get_contents($opts[$key]);
};
$plan = json_decode($read('plan'), true);
$k6 = json_decode($read('k6-summary'), true);
$samplesText = $read('samples');
if (!is_array($plan) || !is_array($k6) || !isset($k6['scenario_start_ms'])) {
    fwrite(STDERR, "summarize-run: the plan or the k6 summary is not what was expected (no scenario start time)\n");
    exit(2);
}

$window = $plan['duration_sec'] - $plan['warmup_sec'];
$rps = RpsAchievement::judge((float) $plan['rps'], (int) $k6['measure_requests'], $window);
$perRoute = [];
foreach ($plan['routes'] as $r) {
    $count = (int) ($k6['per_route'][$r['route']] ?? 0);
    $row = ['route' => $r['route'], 'target_rps' => $r['rps'], 'achieved_rps' => $count / $window, 'requests' => $count];
    if (isset($r['planned_window_requests'])) {
        $row['planned_requests'] = $r['planned_window_requests'];
    }
    $perRoute[] = $row;
}

$start = $k6['scenario_start_ms'] / 1000;
$from = $start + $plan['warmup_sec'];
$to = $start + $plan['duration_sec'];
try {
    $util = DbUtilization::fromSamples(DbUtilization::parseSamples($samplesText), $from, $to);
} catch (CpuStatUnreadable $e) {
    fwrite(STDERR, 'summarize-run: the DB utilization cannot be worked out: ' . $e->getMessage() . "\n");
    exit(3);
}

$app = null;
if (isset($opts['app-samples'])) {
    if (!is_file($opts['app-samples'])) {
        fwrite(STDERR, "summarize-run: --app-samples is not a readable file\n");
        exit(2);
    }
    try {
        $app = DbUtilization::fromSamples(DbUtilization::parseSamples((string) file_get_contents($opts['app-samples'])), $from, $to, (int) ($opts['app-cpus'] ?? 6));
    } catch (CpuStatUnreadable $e) {
        fwrite(STDERR, 'summarize-run: the app CPU utilization cannot be worked out: ' . $e->getMessage() . "\n");
        exit(3);
    }
}

$record = [
    'mix' => $plan['mix'],
    'scheme' => $plan['scheme'] ?? 'spec',
    'rps' => $rps,
    'window_sec' => $window,
    'window_epoch' => ['from' => $from, 'to' => $to],
    'db_utilization' => $util,
    'requests' => ['in_window' => (int) $k6['measure_requests'], 'failed' => (int) $k6['measure_failed'], 'dropped_iterations' => (int) $k6['dropped_iterations']],
    'per_route' => $perRoute,
];
if (isset($k6['http_status']) && is_array($k6['http_status'])) {
    $record['requests']['http_status'] = $k6['http_status'];
}
if (isset($k6['http_status_by_route']) && is_array($k6['http_status_by_route'])) {
    $record['requests']['http_status_by_route'] = $k6['http_status_by_route'];
}
if (array_key_exists('request_timeout', $k6)) {
    $record['requests']['request_timeout'] = $k6['request_timeout'];
}
if ($app !== null) {
    $record['app_cpu_utilization'] = $app;
}
echo json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
