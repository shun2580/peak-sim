<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

use PeakSim\Runtime\Simulate\Calibrate\CalibrationResult;

/**
 * The whole output of a run (simulator-spec.md section 6.4) as one array, in a fixed key order: the same input gives the same JSON.
 * It holds no clock reading and nothing of a target's `observed`.
 */
final class PredictionReport
{
    public const SCHEMA_VERSION = '0.1';

    /** What the row estimates assume about the database they were read from (simulator-spec.md 7.2: the lab's databases are analyzed after the build). Always in the output. */
    /** The sentence added to the note on 2026-11-19 (docs/decisions.md D224, reworded by D230 to say "the same on both" instead of "finished"): the maintenance state of the databases. Kept apart so a test can take it out and show that nothing else in an older record changed. */
    public const ESTIMATE_MAINTENANCE_CLAUSE = '; they also assume that the database the statements were captured on and the database the estimate is used on are in the same maintenance state: the rebuild and aggregate processes of the application (for BookStack, regenerate-references) were run on both or on neither, and then ANALYZE TABLE was run on both, because a table such a process rewrites can hold different rows in another state';

    public const ESTIMATE_BASIS_NOTE = 'the row estimates assume a database whose optimizer statistics are up to date (ANALYZE TABLE was run after the data was built); on a database with stale or missing statistics the plans, and so the estimates, can differ' . self::ESTIMATE_MAINTENANCE_CLAUSE;

    /**
     * @param array<string, mixed>                                                                      $loaded     LoadedInput::toArray()
     * @param array<string, array{table: string|null, a: float, b: float, k: float}|null>              $memory     route => peak-memory formula (null: none)
     * @param array<string, mixed>                                                                      $accuracy   AccuracyRecord::load()
     * @param (callable(array<string, int>, array<string, float>): list<array<string, mixed>>)|null      $evaluate
     *
     * @return array<string, mixed>
     */
    public static function build(array $loaded, CalibrationResult $calibration, array $memory, MemoryAccuracy $memoryAccuracy, array $accuracy, ?callable $evaluate = null): array
    {
        $resources = (array) $loaded['resources'];
        $cores = (float) $resources['cores'];
        $threshold = (float) $resources['threshold'];
        $limitBytes = isset($resources['php_memory_limit_bytes']) ? (int) $resources['php_memory_limit_bytes'] : null;
        // the app's execution limit is an input and has no default: with none given the mark is not made and the output has no trace of it
        $appLimit = isset($resources['app_cpu_time_limit_s']) ? (float) $resources['app_cpu_time_limit_s'] : null;
        $coefficients = $appLimit === null ? null : Coefficients::pointOf($calibration);
        // the memory side of the mark, likewise: an input with no default, and with none given the output has no trace of it
        $appMemoryLimit = isset($resources['app_memory_limit_bytes']) ? (int) $resources['app_memory_limit_bytes'] : null;
        // the placeholder side of the mark, likewise: an input with no default, and with none given the output has no trace of it
        $placeholderLimit = isset($resources['db_placeholder_limit']) ? (int) $resources['db_placeholder_limit'] : null;

        $width = CalibrationWidth::of(PredictStage::points($loaded), $calibration);
        $scenarios = [];
        foreach ((array) $loaded['scenarios'] as $s) {
            if (($s['kind'] ?? null) === 'target') {
                $predicted = ScenarioPredictor::predict($s, $calibration, $cores, $threshold, $evaluate, $width);
                if ($coefficients !== null && $appLimit !== null) {
                    $predicted['app_limit_risk'] = AppLimitRisk::evaluate(array_values((array) ($s['routes'] ?? [])), $coefficients, $appLimit);
                }
                if ($appMemoryLimit !== null) {
                    $predicted['app_limit_risk_memory'] = AppMemoryRisk::evaluate(array_values((array) ($s['routes'] ?? [])), $appMemoryLimit);
                }
                if ($placeholderLimit !== null) {
                    $predicted['app_limit_risk_placeholders'] = AppPlaceholderRisk::evaluate(array_values((array) ($s['routes'] ?? [])), $placeholderLimit);
                }
                $scenarios[] = $predicted;
            }
        }
        $lowCoverage = array_values(array_map(static fn (array $p): string => $p['id'], array_filter($scenarios, static fn (array $p): bool => $p['low_coverage'])));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'app' => $loaded['app'] ?? null,
            'estimate_at' => $loaded['estimate_at'] ?? null,
            'resources' => ['cores' => $cores, 'threshold' => $threshold, 'php_memory_limit_bytes' => $limitBytes] + ($appLimit === null ? [] : ['app_cpu_time_limit_s' => $appLimit]) + ($appMemoryLimit === null ? [] : ['app_memory_limit_bytes' => $appMemoryLimit]) + ($placeholderLimit === null ? [] : ['db_placeholder_limit' => $placeholderLimit]),
            'estimate_basis_note' => self::ESTIMATE_BASIS_NOTE,
            'low_coverage_scenarios' => $lowCoverage,
            'calibration' => $calibration->toArray() + ['width' => $width->toArray()],
            'scenarios' => $scenarios,
            'peak_memory' => [
                'line_share_of_limit' => PeakMemoryLimit::SHARE,
                'accuracy_source' => $memoryAccuracy->source,
                'routes' => PeakMemoryLimit::all($memory, $memoryAccuracy, $limitBytes),
            ],
            'accuracy_on_this_app' => $accuracy,
            'sources' => [
                'static' => 'the static analysis fixed the value',
                'explain' => 'the EXPLAIN estimate gave the value (the upper side of a marked statement)',
                'fit' => 'a formula fitted to measurements at small sizes',
                'unknown' => 'no cost (listed apart, never counted as 0)',
            ],
            'n_marks' => [
                'measured' => 'the count is one of the sizes measured',
                'interpolated' => 'the count is between measured sizes',
                'extrapolated' => 'the count is outside the measured sizes',
            ],
        ];
    }
}
