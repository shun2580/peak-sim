<?php

declare(strict_types=1);

/*
 * How far the committed ladder prediction moves when the calibration coefficient c_row is put at the ends of the leave-one-out range.
 *
 * The values it writes are a SENSITIVITY, not a prediction: the one prediction of the lab backtest is
 * poc/lab/predictions/ladder-prediction.json, and nothing here replaces or re-scores it.
 *
 * It calculates nothing about the database of its own. It loads the estimate and the scenario file as poc/lab/bin/predict-ladder does
 * (the first stage of SimulateCommand, the safe-side estimates at their counts), then, for every target scenario and stage, takes the two rates
 * the model turns into a load (UtilizationModel::rates: queries per second and rows per second, the point reading), and puts a pair of
 * coefficients on them: utilisation = (c_query x queries + c_row x rows) / cores (the form of Calibration's own range,
 * LadderPrediction::calibrationRange). The limit rps is LadderPrediction::limitRps. The estimate, the calibration and the formula are not changed.
 *
 * Coefficient sets (all read from the committed prediction file, none typed here):
 *   main        the calibration on all four runs (the point of the prediction)
 *   without:*   the four leave-one-out fits and "all"; (i) takes the pair with the smallest and the one with the largest c_row,
 *               c_query kept as it is in that pair
 *   (ii)        c_query kept at the value of the fit on all four runs; c_row alone at the smallest and at the largest of the five
 *
 * Self-check: the utilisation of the set "main" must be the committed point utilisation, and each leave-one-out fit must be the committed
 * calibration_range value, at every stage of every scenario; any difference stops this with exit 3 (nothing is written).
 *
 *   php c-row-sensitivity.php --estimate=<dir> --safe-estimate=<dir> ... --scenario=<yaml> --prediction=<json> --score=<json> --out=<dir>
 */

use PeakSim\Runtime\Simulate\Load\EstimateFormulas;
use PeakSim\Runtime\Simulate\Ladder\LadderPrediction;
use PeakSim\Runtime\Simulate\Ladder\SafeSideEstimates;
use PeakSim\Runtime\Simulate\Load\LoadedInput;
use PeakSim\Runtime\Simulate\Load\NativeIo;
use PeakSim\Runtime\Simulate\Load\TwoStage;
use PeakSim\Runtime\Simulate\Predict\UtilizationModel;
use PeakSim\Runtime\Simulate\SimulateCommand;

require $argv[1] ?? '';

$estimate = '';
$safeDirs = [];
$scenarioPath = '';
$predictionPath = '';
$scorePath = '';
$out = '';
foreach (array_slice($argv, 2) as $arg) {
    foreach (['estimate' => 'estimate', 'scenario' => 'scenarioPath', 'prediction' => 'predictionPath', 'score' => 'scorePath', 'out' => 'out'] as $opt => $var) {
        if (str_starts_with($arg, '--' . $opt . '=')) {
            $$var = substr($arg, strlen($opt) + 3);
            continue 2;
        }
    }
    if (str_starts_with($arg, '--safe-estimate=')) {
        $safeDirs[] = substr($arg, strlen('--safe-estimate='));
        continue;
    }
    fwrite(STDERR, "c-row-sensitivity: unknown argument: $arg\n");
    exit(2);
}
if ($estimate === '' || $scenarioPath === '' || $predictionPath === '' || $scorePath === '' || $out === '') {
    fwrite(STDERR, "c-row-sensitivity: --estimate, --scenario, --prediction, --score and --out are required\n");
    exit(2);
}

/** @return array<string, mixed> */
$readJson = static fn (string $p): array => (array) json_decode((string) file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$prediction = $readJson($predictionPath);
$score = $readJson($scorePath);

$next = static function (TwoStage $stage, LoadedInput $input, string $outDir) use ($estimate, $safeDirs, $prediction, $score, $scenarioPath): int {
    $io = new NativeIo();
    $main = EstimateFormulas::load($io, $estimate);
    $others = [];
    foreach ($safeDirs as $d) {
        $others[] = ['estimate' => EstimateFormulas::load($io, $d), 'commit' => null, 'commit_source' => null, 'sha256' => hash('sha256', $io->read(rtrim($d, '/') . '/' . EstimateFormulas::FILE))];
    }
    $safe = new SafeSideEstimates($main, ['commit' => null, 'commit_source' => null, 'sha256' => hash('sha256', $io->read(rtrim($estimate, '/') . '/' . EstimateFormulas::FILE))], $others);
    $loaded = $safe->apply($input->toArray());

    $cores = (float) $loaded['resources']['cores'];
    $threshold = (float) $loaded['resources']['threshold'];
    $stages = array_map('floatval', (array) $prediction['stages_rps_total']);

    $coef = (array) $prediction['calibration']['coefficients'];
    $fits = (array) $prediction['calibration']['width']['fits'];
    $cq = (float) $coef['c_q'];
    $cRows = array_map(static fn (array $f): float => (float) $f['c_row'], $fits);
    $minFit = $fits[array_search(min($cRows), $cRows, true)];
    $maxFit = $fits[array_search(max($cRows), $cRows, true)];
    $sets = [['key' => 'main', 'group' => 'main', 'label' => 'main (fit on all four runs)', 'c_query' => $cq, 'c_row' => (float) $coef['c_row']]];
    foreach ($fits as $f) {
        $sets[] = ['key' => 'fit:' . $f['label'], 'group' => 'leave_one_out', 'label' => (string) $f['label'], 'c_query' => (float) $f['c_query'], 'c_row' => (float) $f['c_row']];
    }
    $sets[] = ['key' => 'i_min', 'group' => '(i)', 'label' => 'c_row smallest, c_query as in the same fit (' . $minFit['label'] . ')', 'c_query' => (float) $minFit['c_query'], 'c_row' => (float) $minFit['c_row']];
    $sets[] = ['key' => 'i_max', 'group' => '(i)', 'label' => 'c_row largest, c_query as in the same fit (' . $maxFit['label'] . ')', 'c_query' => (float) $maxFit['c_query'], 'c_row' => (float) $maxFit['c_row']];
    $sets[] = ['key' => 'ii_min', 'group' => '(ii)', 'label' => 'c_query of the main fit, c_row smallest', 'c_query' => $cq, 'c_row' => min($cRows)];
    $sets[] = ['key' => 'ii_max', 'group' => '(ii)', 'label' => 'c_query of the main fit, c_row largest', 'c_query' => $cq, 'c_row' => max($cRows)];

    $committed = [];
    foreach ((array) $prediction['scenarios'] as $s) {
        $committed[(string) $s['id']] = $s;
    }
    $measured = [];
    foreach ((array) $score['scenarios'] as $s) {
        $measured[(string) $s['id']] = (float) $s['limit']['measured']['rps'];
    }
    $rates = static function (array $scenario, float $total): array {
        $r = UtilizationModel::rates(array_values((array) LadderPrediction::scenarioAt($scenario, $total)['routes']), UtilizationModel::POINT);

        return [$r['queries'], $r['rows']];
    };

    $report = [
        'schema_version' => '0.1',
        'kind' => 'c-row-sensitivity',
        'note' => 'a sensitivity of the one committed prediction to the calibration coefficient c_row; not a prediction, not scored',
        'cores' => $cores,
        'threshold' => $threshold,
        'stages_rps_total' => $stages,
        'sets' => array_map(static fn (array $s): array => ['key' => $s['key'], 'group' => $s['group'], 'label' => $s['label'], 'c_query' => $s['c_query'], 'c_row' => $s['c_row']], $sets),
        'scenarios' => [],
    ];
    $worst = 0.0;
    foreach ((array) $loaded['scenarios'] as $scenario) {
        if (($scenario['kind'] ?? null) !== 'target') {
            continue;
        }
        $id = (string) $scenario['id'];
        $own = 0.0;
        foreach ((array) $scenario['routes'] as $r) {
            $own += (float) $r['rps'];
        }
        [$qOwn, $rOwn] = $rates($scenario, $own);
        $byStage = [];
        foreach ($stages as $i => $total) {
            [$q, $r] = $rates($scenario, $total);
            $row = ['rps_total' => $total, 'queries_per_s' => $q, 'rows_per_s' => $r, 'utilization' => []];
            foreach ($sets as $set) {
                $row['utilization'][$set['key']] = ($set['c_query'] * $q + $set['c_row'] * $r) / $cores;
            }
            $worst = max($worst, abs($row['utilization']['main'] - (float) $committed[$id]['stages'][$i]['utilization']['point']));
            foreach ($committed[$id]['calibration_range']['fits'] as $f) {
                $worst = max($worst, abs($row['utilization']['fit:' . $f['label']] - (float) $f['utilization'][$i]));
            }
            $byStage[] = $row;
        }
        $limits = [];
        foreach ($sets as $set) {
            $u = ($set['c_query'] * $qOwn + $set['c_row'] * $rOwn) / $cores;
            $l = LadderPrediction::limitRps($u, $own, $threshold);
            $limits[$set['key']] = [
                'limit_rps' => $l['rps'],
                'versus_main' => $l['rps'] === null ? null : $l['rps'] / (float) $committed[$id]['limit_rps']['point']['rps'] - 1.0,
                'versus_measured' => $l['rps'] === null ? null : $l['rps'] / $measured[$id] - 1.0,
            ];
        }
        $worst = max($worst, abs($limits['main']['limit_rps'] - (float) $committed[$id]['limit_rps']['point']['rps']));
        $report['scenarios'][] = [
            'id' => $id,
            'tables' => $scenario['tables'],
            'rps_total_in_file' => $own,
            'measured_limit_rps' => $measured[$id],
            'rows_per_query_at_file_mix' => $rOwn / $qOwn,
            'stages' => $byStage,
            'limits' => $limits,
        ];
    }
    $report['self_check_max_abs_difference'] = $worst;
    if ($worst > 1e-12) {
        fwrite(STDERR, "c-row-sensitivity: the main set and the leave-one-out sets do not reproduce the committed prediction (max difference $worst); nothing written\n");

        return 3;
    }
    $stage->writeOutput(
        rtrim($outDir, '/') . '/sensitivity.json',
        json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    fwrite(STDOUT, "self-check: max abs difference from the committed prediction = $worst\n");

    return 0;
};

exit((new SimulateCommand(STDOUT, STDERR, null, $next))->run(['--estimate=' . $estimate, '--scenario=' . $scenarioPath, '--out=' . $out]));
