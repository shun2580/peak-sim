<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Ladder;

use PeakSim\Runtime\Backtest\NaiveEstimate;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationResult;
use PeakSim\Runtime\Simulate\Predict\AppLimitRisk;
use PeakSim\Runtime\Simulate\Predict\AppMemoryRisk;
use PeakSim\Runtime\Simulate\Predict\AppPlaceholderRisk;
use PeakSim\Runtime\Simulate\Predict\CalibrationWidth;
use PeakSim\Runtime\Simulate\Predict\Coefficients;
use PeakSim\Runtime\Simulate\Predict\LimitSearch;
use PeakSim\Runtime\Simulate\Predict\PredictionReport;
use PeakSim\Runtime\Simulate\Predict\PredictStage;
use PeakSim\Runtime\Simulate\Predict\ScenarioPredictor;
use PeakSim\Runtime\Simulate\Predict\UtilizationModel;

/**
 * The prediction of the lab backtest on its rps ladder (simulator-spec.md sections 7.2 and 7.3), as one array in a fixed key order.
 *
 * It calculates nothing of its own about the database. The utilisation of a stage is what {@see ScenarioPredictor::predict} gives for the
 * target scenario with every route's rps put at that stage's total, in the mix the scenario file gives; the calibration is
 * {@see PredictStage::points} and `Calibrator` (called by the step that uses this class); the naive value is {@see NaiveEstimate}. So the
 * utilisation of a stage is the same number `bin/simulate` prints for a scenario file that writes the same rps.
 *
 * The limit rps is solved from the proportional form of the model (the load is in proportion to the rps of every route, section 5), not
 * searched in steps: limit = threshold x total / utilisation, with the utilisation read at the scenario's own total. The same is done for
 * the safe side (the upper side of the marked routes) and for the naive estimate (threshold x basis rps / basis utilisation).
 *
 * Units: rps is requests per second (a total over all routes, or per route); a utilisation is a share of the database's CPU (0 to 1, as in
 * `prediction.json`); the threshold is a share too.
 *
 * No clock and no randomness: the same input gives the same JSON. A target's `observed` is never here (the loaded input has none).
 */
final class LadderPrediction
{
    public const SCHEMA_VERSION = '0.1';

    public const KIND = 'lab-ladder-prediction';

    /** The rps ladder of section 7.2: totals over all routes, fixed before any model was built. */
    public const STAGES = [0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0];

    /**
     * The ladder of section 7.4.2 (the third backtest): the 13 stages above with 0.025 and 0.035 under the lowest. Used for the
     * scenarios s-1 .. s-5 and for no other (see {@see stagesFor}); the 13 above stay what they were for every other scenario file.
     */
    public const STAGES_S = [0.025, 0.035, 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0];

    /** The id of a target scenario of the third backtest: s-1 .. s-5. */
    private const THIRD_ID = '/^s-[1-5]$/';

    public const LIMIT_FOUND = 'found';

    /** The scenario puts no load on the database under this reading (no costed route): there is no rps that reaches the threshold. */
    public const LIMIT_NO_LOAD = 'no_load';

    /**
     * @param array<string, mixed>                                                                                                $loaded      LoadedInput::toArray()
     * @param array{estimate_commit: string|null, estimate_commit_source: string|null, estimate_sha256: string, scenario_sha256: string} $inputs    where the estimate and the scenario file came from
     * @param int                                                                                                                 $naiveCount  N_0, the count of rows the naive estimate is chosen at: only the calibration scenarios at exactly this
     *                                                                                                                                 count are candidates (no default: the caller says it)
     * @param list<float>|null                                                                                                    $stages      the totals of the ladder; null: {@see stagesFor} (15 for s-1 .. s-5, else the 13 of section 7.2)
     * @param float|null                                                                                                          $requestTimeoutS null: the mix is used as the file gives it (the output is what it always was). A number: the request timeout of B in
     *                                                                                                                                 seconds; the routes the placeholder side or the memory side marks are taken out of every target's mix and their
     *                                                                                                                                 share is given to the rest of their group ({@see RouteAllocation}), and the run stops if a group is left empty or a
     *                                                                                                                                 request is predicted to take more than half of that timeout
     * @param bool                                                                                                                $warmup      true (only with `$requestTimeoutS`): every stage gets `warmup_sec` and `warmup_basis` by {@see StepWarmup}
     *                                                                                                                                 (simulator-spec.md section 7.4.5); false: the output has no trace of them
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when there is no target scenario, no calibration scenario at `$naiveCount`, or the calibration cannot be made from the loaded input
     * @throws AllocationStop             with `$requestTimeoutS`: a limit it needs is not given, a group is left with no route, or a request is too long
     */
    public static function build(array $loaded, CalibrationResult $calibration, array $inputs, int $naiveCount, ?array $stages = null, ?float $requestTimeoutS = null, bool $warmup = false): array
    {
        if ($warmup && $requestTimeoutS === null) {
            throw new \InvalidArgumentException('the warm-up is decided from the routes that stay in the mix after the allocation, so it needs the allocation (the request timeout of B)');
        }
        $stages ??= self::stagesFor($loaded);
        $resources = (array) $loaded['resources'];
        $cores = (float) $resources['cores'];
        $threshold = (float) $resources['threshold'];

        $points = PredictStage::points($loaded);
        $width = CalibrationWidth::of($points, $calibration);
        // the naive estimate is chosen among the calibration scenarios at the count N_0 the caller gives, and at no other count: not the
        // largest count there is, for a calibration scenario at a larger count (cal-5) is no basis for it (simulator-spec.md section 7.3, D183)
        $counts = [];
        foreach ((array) $loaded['scenarios'] as $s) {
            if (($s['kind'] ?? null) === 'calibration') {
                $counts[(string) $s['id']] = self::tableCount($s);
            }
        }
        if (!in_array($naiveCount, $counts, true)) {
            throw new \InvalidArgumentException(sprintf('no calibration scenario is at %s rows (N_0), so the naive estimate has no basis', number_format($naiveCount)));
        }
        $naiveBasis = [];
        $naiveExcluded = [];
        foreach ((array) $loaded['scenarios'] as $s) {
            if (($s['kind'] ?? null) !== 'calibration') {
                continue;
            }
            if ($counts[(string) $s['id']] !== $naiveCount) {
                $naiveExcluded[] = ['id' => (string) $s['id'], 'count' => $counts[(string) $s['id']]];

                continue;
            }
            $naiveBasis[] = [
                'id' => (string) $s['id'],
                'rps_total' => self::rpsTotal($s),
                'utilization' => (float) $s['observed']['db_cpu_utilization'],
            ];
        }

        // the app's execution limit is an input with no default; with none given the mark is not made and the output has no trace of it
        $appLimit = isset($resources['app_cpu_time_limit_s']) ? (float) $resources['app_cpu_time_limit_s'] : null;
        $coefficients = $appLimit === null ? null : Coefficients::pointOf($calibration);
        // the memory side of the mark, likewise: an input with no default, and with none given the output has no trace of it
        $appMemoryLimit = isset($resources['app_memory_limit_bytes']) ? (int) $resources['app_memory_limit_bytes'] : null;
        // the placeholder side of the mark, likewise: an input with no default, and with none given the output has no trace of it
        $placeholderLimit = isset($resources['db_placeholder_limit']) ? (int) $resources['db_placeholder_limit'] : null;
        $allocating = $requestTimeoutS !== null;
        if ($allocating && ($placeholderLimit === null || $appMemoryLimit === null)) {
            throw new AllocationStop('the routes that cannot run under the settings of B are taken out of the mix by the placeholder limit and the memory limit, and the scenario file gives ' . ($placeholderLimit === null ? ($appMemoryLimit === null ? 'neither of them' : 'no db_placeholder_limit') : 'no app_memory_limit'), RouteAllocation::EXIT_NO_LIMITS);
        }
        $half = $allocating ? $requestTimeoutS / 2.0 : 0.0;
        $pointCoefficients = $allocating ? Coefficients::pointOf($calibration) : null;
        $scenarios = [];
        $slowFound = [];
        $emptyFound = [];
        foreach ((array) $loaded['scenarios'] as $s) {
            if (($s['kind'] ?? null) === 'target') {
                $placeholderRisk = $placeholderLimit === null ? null : AppPlaceholderRisk::evaluate(array_values((array) $s['routes']), $placeholderLimit);
                $memoryRisk = $appMemoryLimit === null ? null : AppMemoryRisk::evaluate(array_values((array) $s['routes']), $appMemoryLimit);
                $allocation = null;
                $used = $s;
                if ($allocating && $placeholderRisk !== null && $memoryRisk !== null && $pointCoefficients !== null) {
                    foreach (RouteAllocation::slowRoutes(array_values((array) $s['routes']), $pointCoefficients, $half) as $slow) {
                        $slowFound[] = sprintf('%s: %s, %.1f s of php CPU time for one request', (string) $s['id'], $slow['route'], $slow['php_cpu_bound_s']);
                    }
                    $made = RouteAllocation::apply($s, $placeholderRisk, $memoryRisk);
                    foreach ($made['emptied'] as $group) {
                        $emptyFound[] = sprintf('%s: every route of the group (%s) is taken out', (string) $s['id'], implode(', ', $group));
                    }
                    $used = $made['scenario'];
                    $allocation = $made['record'] + ['longest_request' => RouteAllocation::longestRequest(array_values((array) $s['routes']), $pointCoefficients, $half)];
                }
                $made = self::scenario($used, $calibration, $width, $naiveBasis, $cores, $threshold, $stages, $warmup && $pointCoefficients !== null ? StepWarmup::bounds(array_values((array) $used['routes']), $pointCoefficients) : null);
                if ($coefficients !== null && $appLimit !== null) {
                    $made['app_limit_risk'] = AppLimitRisk::evaluate(array_values((array) $s['routes']), $coefficients, $appLimit);
                }
                if ($memoryRisk !== null) {
                    $made['app_limit_risk_memory'] = $memoryRisk;
                }
                if ($placeholderRisk !== null) {
                    $made['app_limit_risk_placeholders'] = $placeholderRisk;
                }
                if ($allocation !== null) {
                    $made['allocation'] = $allocation;
                }
                $scenarios[] = $made;
            }
        }
        if ($slowFound !== [] || $emptyFound !== []) {
            throw new AllocationStop(
                ($slowFound === [] ? '' : sprintf("a request is predicted to take more than half of the request timeout (%s s, so over %s s of php CPU time):\n  ", self::seconds($requestTimeoutS ?? 0.0), self::seconds($half)) . implode("\n  ", $slowFound))
                . ($slowFound !== [] && $emptyFound !== [] ? "\n" : '')
                . ($emptyFound === [] ? '' : "a group is left with no route, so the load of its share has nowhere to go:\n  " . implode("\n  ", $emptyFound)),
                $slowFound !== [] ? RouteAllocation::EXIT_SLOW_REQUEST : RouteAllocation::EXIT_EMPTY_GROUP,
            );
        }
        if ($scenarios === []) {
            throw new \InvalidArgumentException('the scenario file has no target scenario to predict');
        }

        $basis = NaiveEstimate::estimate($naiveBasis, 1.0);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => self::KIND,
            'app' => $loaded['app'] ?? null,
            'estimate_at' => $loaded['estimate_at'] ?? null,
            'estimate_basis_note' => PredictionReport::ESTIMATE_BASIS_NOTE,
            'resources' => ['cores' => $cores, 'threshold' => $threshold] + ($appLimit === null ? [] : ['app_cpu_time_limit_s' => $appLimit]) + ($appMemoryLimit === null ? [] : ['app_memory_limit_bytes' => $appMemoryLimit]) + ($placeholderLimit === null ? [] : ['db_placeholder_limit' => $placeholderLimit]),
            'stages_rps_total' => array_values($stages),
            'inputs' => $inputs,
            'calibration' => [
                'coefficients' => Coefficients::pointOf($calibration)->toArray(),
            ] + $calibration->toArray() + ['width' => $width->toArray()],
            'naive' => [
                'basis_scenario' => $basis['basis_id'],
                'basis_utilization' => $basis['basis_utilization'],
                'basis_rps_total' => $basis['basis_rps_total'],
                'basis_count' => $naiveCount,
                'candidates' => array_column($naiveBasis, 'id'),
                'not_candidates' => $naiveExcluded,
            ],
            'scenarios' => $scenarios,
        ] + ($allocating ? ['allocation' => [
            'enabled' => true,
            'request_timeout_s' => $requestTimeoutS,
            'half_request_timeout_s' => $half,
            'drop_rule' => RouteAllocation::DROP_RULE,
            'stop_rule' => RouteAllocation::STOP_RULE,
        ]] : []) + ($warmup ? ['warmup' => [
            'enabled' => true,
            'minimum_sec' => StepWarmup::MINIMUM_SEC,
            'rule' => StepWarmup::RULE,
        ]] : []);
    }

    private static function seconds(float $s): string
    {
        return rtrim(rtrim(number_format($s, 3, '.', ''), '0'), '.');
    }

    /**
     * The target scenario with the rps of every route scaled so that they add up to `$total`, the mix kept. The scenario as the loaded input
     * gives it is not changed. Routes with no cost stay in (they carry rps and count in the coverage).
     *
     * @param array<string, mixed> $scenario
     *
     * @return array<string, mixed>
     */
    public static function scenarioAt(array $scenario, float $total): array
    {
        $sum = self::rpsTotal($scenario);
        if ($sum <= 0.0) {
            throw new \InvalidArgumentException('the scenario "' . (string) $scenario['id'] . '" has a sum of rps of 0: its mix is undefined');
        }
        $factor = $total / $sum;
        $routes = [];
        foreach ((array) $scenario['routes'] as $r) {
            $r['rps'] = (float) $r['rps'] * $factor;
            $routes[] = $r;
        }
        $scenario['routes'] = $routes;

        return $scenario;
    }

    /**
     * The total rps (rps total of all routes) at which the utilisation reaches the threshold, from the utilisation read at `$total`.
     *
     * @return array{status: string, rps: float|null}
     */
    public static function limitRps(float $utilisation, float $total, float $threshold): array
    {
        if (!($utilisation > 0.0)) {
            return ['status' => self::LIMIT_NO_LOAD, 'rps' => null];
        }

        return ['status' => self::LIMIT_FOUND, 'rps' => $threshold * $total / $utilisation];
    }

    /**
     * The count of rows a scenario was measured or is set at: the largest of its tables (in the lab all the N tables have the same count).
     *
     * @param array<string, mixed> $scenario
     */
    public static function tableCount(array $scenario): int
    {
        $counts = array_map('intval', array_values((array) ($scenario['tables'] ?? [])));

        return $counts === [] ? 0 : max($counts);
    }

    /** @param array<string, mixed> $scenario */
    private static function rpsTotal(array $scenario): float
    {
        $sum = 0.0;
        foreach ((array) $scenario['routes'] as $r) {
            $sum += (float) $r['rps'];
        }

        return $sum;
    }

    /**
     * The ladder a scenario file is predicted at: the 15 stages of section 7.4.2 when every target scenario is one of s-1 .. s-5, the 13 of
     * section 7.2 when none is. A file with both is refused, for one prediction file has one list of stages.
     *
     * @param array<string, mixed> $loaded LoadedInput::toArray()
     *
     * @return list<float>
     *
     * @throws \InvalidArgumentException the targets are some of the third backtest's and some not
     */
    public static function stagesFor(array $loaded): array
    {
        $third = 0;
        $other = 0;
        foreach ((array) $loaded['scenarios'] as $s) {
            if (($s['kind'] ?? null) !== 'target') {
                continue;
            }
            preg_match(self::THIRD_ID, (string) $s['id']) === 1 ? ++$third : ++$other;
        }
        if ($third > 0 && $other > 0) {
            throw new \InvalidArgumentException('the target scenarios are some of s-1 .. s-5 (15 stages) and some not (13 stages); one prediction file has one ladder, so make them separate files');
        }

        return $third > 0 ? self::STAGES_S : self::STAGES;
    }

    /**
     * @param array<string, mixed>                                                      $scenario
     * @param list<array{id: string, rps_total: float, utilization: float}>             $naiveBasis
     * @param list<float>                                                               $stages
     * @param array<string, float>|null                                                 $warmupBounds the CPU-time mark's bound of each route in the mix ({@see StepWarmup::bounds}); null: no warm-up is written
     *
     * @return array<string, mixed>
     */
    private static function scenario(array $scenario, CalibrationResult $calibration, CalibrationWidth $width, array $naiveBasis, float $cores, float $threshold, array $stages, ?array $warmupBounds = null): array
    {
        $own = self::rpsTotal($scenario);
        $ownPrediction = ScenarioPredictor::predict($scenario, $calibration, $cores, $threshold, null, $width);
        $ownPoint = (float) $ownPrediction['utilization']['point'];
        $ownUpper = (float) $ownPrediction['utilization']['upper'];
        $naiveOwn = NaiveEstimate::estimate($naiveBasis, $own);

        $rows = [];
        foreach ($stages as $total) {
            $at = self::scenarioAt($scenario, (float) $total);
            $p = ScenarioPredictor::predict($at, $calibration, $cores, $threshold, null, $width);
            $point = (float) $p['utilization']['point'];
            $upper = (float) $p['utilization']['upper'];
            $naive = NaiveEstimate::estimate($naiveBasis, (float) $total)['utilization'];
            $rps = [];
            foreach ((array) $at['routes'] as $r) {
                $rps[(string) $r['route']] = (float) $r['rps'];
            }
            $rows[] = [
                'rps_total' => (float) $total,
                'rps' => $rps,
                'utilization' => ['point' => $point, 'upper' => $upper],
                'naive' => $naive,
                'over_threshold' => [
                    'point' => LimitSearch::exceeds($point, $threshold),
                    'upper' => LimitSearch::exceeds($upper, $threshold),
                    'naive' => LimitSearch::exceeds($naive, $threshold),
                ],
            ] + ($warmupBounds === null ? [] : StepWarmup::forStage($warmupBounds, $rps));
        }

        $marked = [];
        foreach ((array) $scenario['routes'] as $r) {
            if (($r['marked'] ?? false) === true) {
                $marked[] = (string) $r['route'];
            }
        }
        $tables = [];
        foreach ((array) ($scenario['tables'] ?? []) as $name => $count) {
            $tables[(string) $name] = (int) $count;
        }

        return [
            'id' => (string) $scenario['id'],
            'tables' => $tables,
            'rps_total_in_file' => $own,
            'safe_side' => $scenario['safe_side'] ?? null,
            'marked_routes' => $marked,
            'low_coverage' => $ownPrediction['low_coverage'],
            'unknown_tables' => $ownPrediction['unknown_tables'],
            'warnings' => $ownPrediction['warnings'],
            'stages' => $rows,
            'limit_rps' => [
                'point' => self::limitRps($ownPoint, $own, $threshold),
                'safe' => self::limitRps($ownUpper, $own, $threshold),
                'naive' => self::limitRps($naiveOwn['utilization'], $own, $threshold),
            ],
            'calibration_range' => self::calibrationRange($scenario, $width, $cores, $threshold, $stages),
        ];
    }

    /**
     * What the width of the calibration does to this scenario: for each fit of the width (all the calibration scenarios, and each one left
     * out in turn), its coefficients, the utilisation it gives at every stage and the limit rps it gives (from the proportional form, as for the
     * point). It is a record of how far the calibration alone moves the prediction; it is not used to decide a pass or a failure (section 6.3:
     * the decision is by the point value). The point reading of the estimate is used, so this is the calibration's width alone.
     *
     * @param array<string, mixed> $scenario
     * @param list<float>          $stages
     *
     * @return array<string, mixed>
     */
    private static function calibrationRange(array $scenario, CalibrationWidth $width, float $cores, float $threshold, array $stages): array
    {
        $head = ['for_the_decision' => false, 'status' => $width->status, 'reason' => $width->reason, 'notes' => $width->notes];
        if (!$width->isAvailable()) {
            return $head + ['fits' => [], 'stages' => [], 'limit_rps' => null];
        }
        $own = self::rpsTotal($scenario);
        $rates = static function (array $at): array {
            $r = UtilizationModel::rates(array_values((array) $at['routes']), UtilizationModel::POINT);

            return [$r['queries'], $r['rows']];
        };
        $fits = [];
        foreach ($width->fits as $f) {
            $fits[$f['label']] = [
                'label' => $f['label'],
                'c_query' => $f['fit']->cQuery,
                'c_row' => $f['fit']->cRow,
                'cut_coefficient' => $f['fit']->hasCutCoefficient(),
                'utilization' => [],
                'limit_rps' => null,
            ];
        }
        [$q, $r] = $rates($scenario);
        foreach ($width->loads($q, $r) as $label => $load) {
            $fits[$label]['limit_rps'] = self::limitRps($load / $cores, $own, $threshold);
        }
        $rows = [];
        foreach ($stages as $total) {
            [$q, $r] = $rates(self::scenarioAt($scenario, (float) $total));
            $loads = $width->loads($q, $r);
            foreach ($loads as $label => $load) {
                $fits[$label]['utilization'][] = $load / $cores;
            }
            $values = array_map(static fn (float $l): float => $l / $cores, array_values($loads));
            $rows[] = ['rps_total' => (float) $total, 'min' => min($values), 'max' => max($values)];
        }
        $limits = [];
        foreach ($fits as $f) {
            if (($f['limit_rps']['status'] ?? null) === self::LIMIT_FOUND) {
                $limits[] = (float) $f['limit_rps']['rps'];
            }
        }

        return $head + [
            'fits' => array_values($fits),
            'stages' => $rows,
            'limit_rps' => $limits === [] ? null : ['min' => min($limits), 'max' => max($limits)],
        ];
    }
}
