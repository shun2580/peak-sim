<?php
declare(strict_types=1);
// Batch form of poc/lab/scenarios/calibration-gate.php: the same parts in the same order (CalibrationScenario::rpsOfPlan, InputLoader::loadParsed,
// CalibrationPoint::fromRoutes, CalibrationGate::evaluate), called over a grid in one process instead of one process per point.
//   php gate_batch.php <estimate dir> <plans dir> <set: 7|8> <shard> <shards> <out.tsv>
// Grid: R_3 in 0.3, 0.37, 0.45, 0.7; R_4 in 14 values; R_0, R_1 in 1,2,4,8,16,32; R_2 in 14 values; set 8 adds R_5 (cal-8: 1,000 rows, even) in 1,2,4,8,16,32.
require '/peak-sim/poc/lab/scenarios/CalibrationScenario.php';
require '/peak-sim/poc/runtime/bootstrap.php';
use PeakSim\Lab\Scenarios\CalibrationScenario;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationGate;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationPoint;
use PeakSim\Runtime\Simulate\Load\InputLoader;
use PeakSim\Runtime\Simulate\Load\NativeIo;
[, $est, $pd, $set, $shard, $shards, $out] = $argv; $shard = (int) $shard; $shards = (int) $shards;
$RG = ['1','2','4','8','16','32']; $R2G = ['0.05','0.07','0.1','0.15','0.2','0.3','0.37','0.45','0.7','1','1.5','2.2','3.3','5'];
$R3 = ['0.3','0.37','0.45','0.7']; $R4 = $R2G; $R5 = $set === '8' ? $RG : [null];
$cache = [];
$rps = function (string $id, string $file) use (&$cache, $pd) {
    return $cache[$file] ??= CalibrationScenario::rpsOfPlan($id, json_decode(file_get_contents("$pd/$file"), true));
};
$io = new InputLoader(new NativeIo());
$fh = fopen($out, 'w'); $i = 0;
foreach ($R3 as $r3) foreach ($R4 as $r4) foreach ($RG as $r0) foreach ($RG as $r1) foreach ($R2G as $r2) foreach ($R5 as $r5) {
    if ($i++ % $shards !== $shard) continue;
    $runs = [['cal-1', 10000, "even_r$r0.json"], ['cal-2', 10000, "first80_r$r0.json"], ['cal-3', 10000, "odd80_r$r0.json"], ['cal-4', 3000, "even_r$r1.json"],
        ['cal-5', 30000, "even_r$r2.json"], ['cal-6', 30000, "top1_r$r3.json"], ['cal-7', 30000, "top2_r$r4.json"]];
    if ($r5 !== null) $runs[] = ['cal-8', 1000, "even_r$r5.json"];
    $scn = [];
    foreach ($runs as [$id, $n, $file]) $scn[] = ['id' => $id, 'kind' => 'calibration', 'tables' => ['n_shared' => $n], 'rps' => $rps($id, $file)];
    $loaded = $io->loadParsed($est, ['resources' => ['cores' => 2, 'threshold' => 0.8, 'php_memory_limit_bytes' => null], 'scenarios' => $scn])->toArray();
    $pts = [];
    foreach ($loaded['scenarios'] as $s) $pts[] = CalibrationPoint::fromRoutes((string) $s['id'], 2.0, 0.0, $s['routes']);
    $r = CalibrationGate::evaluate($pts);
    fwrite($fh, implode("\t", [$r3, $r4, $r0, $r1, $r2, $r5 ?? '-', $r['passed'] ? 0 : 14, sprintf('%.6f', $r['min_degree']), $r['min_left_out'] ?? 'all runs']) . "\n");
}
fclose($fh);
